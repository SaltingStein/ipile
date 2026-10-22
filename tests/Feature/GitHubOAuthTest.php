<?php

namespace Tests\Feature;

use App\Models\OAuthProvider;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\SessionAuthenticator;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GitHubOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enable_and_disable_github_without_exposing_its_secret(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'))
            ->put(route('admin.oauth-providers.update', 'github'), [
                'is_enabled' => true, 'client_id' => 'github-id', 'client_secret' => 'github-secret',
            ])->assertSessionHas('status', 'GitHub sign-in settings saved.');
        $provider = OAuthProvider::query()->where('provider', 'github')->firstOrFail();
        $this->assertSame('github-secret', $provider->client_secret);
        $this->assertNotSame('github-secret', $provider->getRawOriginal('client_secret'));
        $this->get(route('admin.authentication.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('providers.1.provider', 'github')
                ->where('providers.1.has_client_secret', true)
                ->where('providers.1.callback_url', route('oauth.callback', 'github')))
            ->assertDontSee('github-secret');
        $this->put(route('admin.oauth-providers.update', 'github'), [
            'is_enabled' => false, 'client_id' => 'github-id', 'client_secret' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('github-secret', $provider->fresh()->client_secret);
        $this->get(route('oauth.redirect', 'github'))->assertNotFound();
    }

    public function test_github_settings_require_manage_settings_permission(): void
    {
        $this->actingAs(User::factory()->create())->put(route('admin.oauth-providers.update', 'github'), ['is_enabled' => false])->assertForbidden();
        $this->assertDatabaseCount('oauth_providers', 0);
    }

    public function test_enabled_github_is_advertised_and_redirects_with_email_scope_and_state(): void
    {
        $this->enableGitHub();
        $this->getJson(route('api.v1.auth.oauth.providers'))->assertExactJson(['data' => [['provider' => 'github', 'label' => 'GitHub']]]);
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('oauthProviders.0.provider', 'github'));
        $response = $this->get(route('oauth.redirect', 'github'));
        $response->assertRedirect();
        $url = parse_url($response->headers->get('Location'));
        parse_str($url['query'], $query);
        $this->assertSame('github.com', $url['host']);
        $this->assertSame('github-id', $query['client_id']);
        $this->assertSame(route('oauth.callback', 'github'), $query['redirect_uri']);
        $this->assertSame('user:email', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $response->assertSessionHas('state', $query['state']);
    }

    public function test_callback_uses_the_verified_private_primary_email(): void
    {
        $this->enableGitHub();
        $this->mockGitHub();
        $this->completeCallback()->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'private@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'github', 'provider_user_id' => '42']);
    }

    public function test_invalid_state_is_rejected_before_contacting_github(): void
    {
        $this->enableGitHub();
        $this->mockGitHub(responses: []);
        $this->withSession(['state' => 'expected'])->get(route('oauth.callback', ['provider' => 'github', 'state' => 'wrong', 'code' => 'code']))
            ->assertRedirect(route('login'))->assertSessionHasErrors(['oauth' => 'GitHub sign-in was cancelled or failed. Please try again.']);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('unusableEmails')]
    public function test_new_accounts_require_a_verified_primary_email(array $emails): void
    {
        $this->enableGitHub();
        $this->mockGitHub(emails: $emails);
        $this->completeCallback()->assertSessionHasErrors(['oauth' => 'Your GitHub account did not share an email address.']);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public static function unusableEmails(): array
    {
        return [
            'none shared' => [[]],
            'unverified primary' => [[['email' => 'private@example.com', 'primary' => true, 'verified' => false]]],
            'verified secondary' => [[['email' => 'private@example.com', 'primary' => false, 'verified' => true]]],
        ];
    }

    public function test_matching_email_does_not_link_an_existing_account(): void
    {
        $this->enableGitHub();
        User::factory()->create(['email' => 'private@example.com']);
        $this->mockGitHub();
        $this->completeCallback()->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_guest_is_upgraded_in_place(): void
    {
        $this->enableGitHub();
        $guest = User::factory()->guest()->create();
        $this->mockGitHub();
        $this->actingAs($guest)->completeCallback()->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $guest->id, 'is_guest' => false, 'email' => 'private@example.com']);
    }

    public function test_closed_registration_prevents_new_accounts(): void
    {
        $this->enableGitHub();
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');
        $this->mockGitHub();
        $this->completeCallback()->assertSessionHasErrors(['oauth' => 'Registration is currently closed.']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_closed_registration_still_allows_linked_users_to_reach_two_factor(): void
    {
        $this->enableGitHub();
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');
        $user = User::factory()->withTwoFactor()->create();
        SocialAccount::factory()->for($user)->create(['provider' => 'github', 'provider_user_id' => '42']);
        $this->mockGitHub();
        $this->completeCallback()->assertRedirect(route('two-factor.login'))->assertSessionHas(SessionAuthenticator::PENDING_USER, $user->id);
        $this->assertGuest();
    }

    public function test_registered_users_can_link_github_without_changing_their_email(): void
    {
        $this->enableGitHub();
        $user = User::factory()->create();
        $this->mockGitHub();
        $this->actingAs($user)->completeCallback()->assertRedirect(route('security.show'))->assertSessionHas('status', 'GitHub account connected.');
        $this->assertDatabaseHas('social_accounts', ['provider' => 'github', 'user_id' => $user->id]);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_api_exchanges_github_tokens_using_the_same_email_rules(): void
    {
        $this->enableGitHub();
        $this->mockGitHub(web: false);
        $this->postJson(route('api.v1.auth.oauth.token', 'github'), ['access_token' => 'access-token', 'device_name' => 'Phone'])
            ->assertOk()->assertJsonPath('user.email', 'private@example.com');
        $user = User::query()->where('email', 'private@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Phone']);
    }

    public function test_disabled_github_rejects_callbacks_and_api_tokens(): void
    {
        $this->get(route('oauth.callback', 'github'))->assertNotFound();
        $this->postJson(route('api.v1.auth.oauth.token', 'github'), ['access_token' => 'token', 'device_name' => 'Phone'])->assertNotFound();
    }

    private function enableGitHub(): void
    {
        Notification::fake();
        OAuthProvider::create(['provider' => 'github', 'is_enabled' => true, 'client_id' => 'github-id', 'client_secret' => 'github-secret']);
    }

    private function completeCallback(): TestResponse
    {
        return $this->withSession(['state' => 'expected'])->get(route('oauth.callback', ['provider' => 'github', 'state' => 'expected', 'code' => 'code']));
    }

    /**
     * Run the installed driver against a closed queue so unexpected network requests fail.
     *
     * @param  list<array{email: string, primary: bool, verified: bool}>|null  $emails
     * @param  list<Response>|null  $responses
     */
    private function mockGitHub(?array $emails = null, bool $web = true, ?array $responses = null): void
    {
        $responses ??= array_merge($web ? [new Response(200, [], json_encode(['access_token' => 'access-token', 'token_type' => 'bearer']))] : [], [
            new Response(200, [], json_encode(['id' => 42, 'node_id' => 'node-42', 'login' => 'ada', 'name' => 'Ada Obi', 'email' => 'public@example.com', 'avatar_url' => null])),
            new Response(200, [], json_encode($emails ?? [
                ['email' => 'secondary@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'private@example.com', 'primary' => true, 'verified' => true],
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        Socialite::shouldReceive('driver')->with('github')->andReturnUsing(function () use ($client): GithubProvider {
            return (new GithubProvider(request(), config('services.github.client_id'), config('services.github.client_secret'), config('services.github.redirect')))->setHttpClient($client);
        });
    }
}
