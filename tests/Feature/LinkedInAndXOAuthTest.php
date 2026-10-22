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
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;
use Laravel\Socialite\Two\XProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LinkedInAndXOAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $httpHistory = [];

    public static function providers(): array
    {
        return ['LinkedIn' => ['linkedin-openid', 'LinkedIn'], 'X' => ['x', 'X']];
    }

    #[DataProvider('providers')]
    public function test_admin_can_save_encrypted_credentials_and_disable_sign_in(string $provider, string $label): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'))
            ->put(route('admin.oauth-providers.update', $provider), [
                'is_enabled' => true, 'client_id' => 'client-id', 'client_secret' => 'client-secret',
            ])->assertSessionHas('status', "$label sign-in settings saved.");
        $stored = OAuthProvider::query()->where('provider', $provider)->firstOrFail();
        $this->assertNotSame('client-secret', $stored->getRawOriginal('client_secret'));
        $this->get(route('admin.authentication.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($options): bool => collect($options)->contains(
                fn (array $option): bool => $option['provider'] === $provider
                    && $option['has_client_secret']
                    && $option['callback_url'] === route('oauth.callback', $provider)
            )))
            ->assertDontSee('client-secret');
        $this->put(route('admin.oauth-providers.update', $provider), [
            'is_enabled' => false, 'client_id' => 'client-id', 'client_secret' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('client-secret', $stored->fresh()->client_secret);
        $this->get(route('oauth.redirect', $provider))->assertNotFound();
    }

    #[DataProvider('providers')]
    public function test_provider_settings_require_permission(string $provider, string $label): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('admin.oauth-providers.update', $provider), ['is_enabled' => false])->assertForbidden();
        $this->assertDatabaseCount('oauth_providers', 0);
    }

    #[DataProvider('providers')]
    public function test_enabled_providers_are_advertised_on_web_and_mobile(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $this->getJson(route('api.v1.auth.oauth.providers'))
            ->assertExactJson(['data' => [['provider' => $provider, 'label' => $label]]]);
        foreach (['login', 'register'] as $page) {
            $this->get(route($page))->assertInertia(fn (Assert $page) => $page->where('oauthProviders.0.provider', $provider));
        }
    }

    #[DataProvider('providers')]
    public function test_web_flow_preserves_state_scopes_and_x_pkce(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $redirect = $this->get(route('oauth.redirect', $provider));
        $redirect->assertRedirect();
        $url = parse_url($redirect->headers->get('Location'));
        parse_str($url['query'], $query);
        $this->assertSame($provider === 'x' ? 'x.com' : 'www.linkedin.com', $url['host']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame(route('oauth.callback', $provider), $query['redirect_uri']);
        $this->assertEqualsCanonicalizing($provider === 'x' ? ['users.read', 'users.email', 'tweet.read'] : ['openid', 'profile', 'email'], explode(' ', $query['scope']));
        $this->assertNotEmpty($query['state']);
        $redirect->assertSessionHas('state', $query['state']);
        $verifier = session('code_verifier');
        if ($provider === 'x') {
            $this->assertNotEmpty($verifier);
            $this->assertSame('S256', $query['code_challenge_method']);
            $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
        }
        $this->mockProvider($provider);
        $this->get(route('oauth.callback', ['provider' => $provider, 'state' => $query['state'], 'code' => 'code']))
            ->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => 'identity-42']);
        if ($provider === 'x') {
            parse_str((string) $this->httpHistory[0]['request']->getBody(), $tokenFields);
            $this->assertSame($verifier, $tokenFields['code_verifier']);
            $this->assertSame('Basic '.base64_encode('client-id:client-secret'), $this->httpHistory[0]['request']->getHeaderLine('Authorization'));
        }
    }

    #[DataProvider('providers')]
    public function test_invalid_state_is_rejected_before_network_access(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $this->mockProvider($provider, responses: []);
        $this->withSession(['state' => 'expected'])->get(route('oauth.callback', ['provider' => $provider, 'state' => 'wrong']))
            ->assertRedirect(route('login'))->assertSessionHasErrors(['oauth' => "$label sign-in was cancelled or failed. Please try again."]);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('providers')]
    public function test_missing_email_cannot_create_an_account(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $this->mockProvider($provider, ['email' => null, 'confirmed_email' => null, 'verified' => true]);
        $this->completeCallback($provider)->assertSessionHasErrors(['oauth' => "Your $label account did not share an email address."]);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('unconfirmedLinkedInEmails')]
    public function test_linkedin_requires_an_explicit_email_verification_claim(?bool $verified): void
    {
        $this->enableProvider('linkedin-openid');
        $this->mockProvider('linkedin-openid', ['email_verified' => $verified]);
        $this->completeCallback('linkedin-openid')->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public static function unconfirmedLinkedInEmails(): array
    {
        return ['not verified' => [false], 'claim omitted' => [null]];
    }

    #[DataProvider('providers')]
    public function test_matching_email_never_auto_links_an_account(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        User::factory()->create(['email' => 'ada@example.com']);
        $this->mockProvider($provider);
        $this->completeCallback($provider)->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    #[DataProvider('providers')]
    public function test_signed_in_users_can_link_an_identity_without_a_shared_email(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $user = User::factory()->create();
        $this->mockProvider($provider, ['email' => null, 'confirmed_email' => null]);
        $this->actingAs($user)->completeCallback($provider)->assertRedirect(route('security.show'))
            ->assertSessionHas('status', "$label account connected.");
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => 'identity-42']);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    #[DataProvider('providers')]
    public function test_closed_registration_prevents_new_accounts(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');
        $this->mockProvider($provider);
        $this->completeCallback($provider)->assertSessionHasErrors(['oauth' => 'Registration is currently closed.']);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('providers')]
    public function test_existing_linked_users_still_require_two_factor_when_registration_is_closed(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');
        $user = User::factory()->withTwoFactor()->create();
        SocialAccount::factory()->for($user)->create(['provider' => $provider, 'provider_user_id' => 'identity-42']);
        $this->mockProvider($provider);
        $this->completeCallback($provider)->assertRedirect(route('two-factor.login'))
            ->assertSessionHas(SessionAuthenticator::PENDING_USER, $user->id);
        $this->assertGuest();
    }

    #[DataProvider('providers')]
    public function test_api_tokens_use_the_same_provider_profile_rules(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $this->mockProvider($provider, web: false);
        $this->postJson(route('api.v1.auth.oauth.token', $provider), ['access_token' => 'access-token', 'device_name' => 'Phone'])
            ->assertOk()->assertJsonPath('user.email', 'ada@example.com');
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Phone']);
    }

    #[DataProvider('providers')]
    public function test_revoked_api_access_tokens_are_rejected(string $provider, string $label): void
    {
        $this->enableProvider($provider);
        $this->mockProvider($provider, responses: [new Response(401)]);
        $this->postJson(route('api.v1.auth.oauth.token', $provider), ['access_token' => 'revoked', 'device_name' => 'Phone'])
            ->assertUnprocessable()->assertJsonValidationErrors('access_token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('providers')]
    public function test_disabled_providers_reject_web_and_api_sign_in(string $provider, string $label): void
    {
        $this->get(route('oauth.redirect', $provider))->assertNotFound();
        $this->get(route('oauth.callback', $provider))->assertNotFound();
        $this->postJson(route('api.v1.auth.oauth.token', $provider), ['access_token' => 'token', 'device_name' => 'Phone'])->assertNotFound();
    }

    private function enableProvider(string $provider): void
    {
        Notification::fake();
        OAuthProvider::create(['provider' => $provider, 'is_enabled' => true, 'client_id' => 'client-id', 'client_secret' => 'client-secret']);
    }

    private function completeCallback(string $provider): TestResponse
    {
        return $this->withSession(['state' => 'expected', 'code_verifier' => 'test-verifier'])
            ->get(route('oauth.callback', ['provider' => $provider, 'state' => 'expected', 'code' => 'code']));
    }

    /**
     * Exercise installed drivers with a closed response queue; no test can contact a provider.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<Response>|null  $responses
     */
    private function mockProvider(string $provider, array $overrides = [], bool $web = true, ?array $responses = null): void
    {
        $profile = $provider === 'x'
            ? ['id' => 'identity-42', 'username' => 'ada', 'name' => 'Ada Obi', 'confirmed_email' => 'ada@example.com', 'profile_image_url' => null]
            : ['sub' => 'identity-42', 'name' => 'Ada Obi', 'given_name' => 'Ada', 'family_name' => 'Obi', 'email' => 'ada@example.com', 'email_verified' => true];
        $profile = array_replace($profile, $overrides);
        $responses ??= array_merge($web ? [new Response(200, [], json_encode(['access_token' => 'access-token', 'token_type' => 'bearer']))] : [], [
            new Response(200, [], json_encode($provider === 'x' ? ['data' => $profile] : $profile)),
        ]);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->httpHistory));
        $client = new Client(['handler' => $stack]);
        Socialite::shouldReceive('driver')->with($provider)->andReturnUsing(function () use ($provider, $client): AbstractProvider {
            $driverClass = $provider === 'x' ? XProvider::class : LinkedInOpenIdProvider::class;

            return (new $driverClass(request(), config("services.$provider.client_id"), config("services.$provider.client_secret"), config("services.$provider.redirect")))->setHttpClient($client);
        });
    }
}
