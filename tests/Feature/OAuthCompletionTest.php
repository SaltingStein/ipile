<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\OAuthProvider;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\OAuthTokenResolver;
use App\Services\Auth\PendingOAuthProfile;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormTemplates;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class OAuthCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_enable_completion_and_invalid_input_keeps_the_saved_value(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        $payload = ['registration_enabled' => true, 'trusted_device_days' => 30, 'two_factor_required_roles' => []];

        $this->actingAs($manager)->put(route('admin.authentication.update'), [...$payload, 'oauth_profile_required' => true])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['key' => AuthenticationSettings::OAUTH_PROFILE_REQUIRED, 'value' => '1']);
        $this->get(route('admin.authentication.edit'))->assertInertia(fn (Assert $page) => $page->where('settings.oauth_profile_required', true));
        $this->put(route('admin.authentication.update'), [...$payload, 'oauth_profile_required' => 'invalid'])
            ->assertSessionHasErrors('oauth_profile_required');
        $this->assertDatabaseHas('settings', ['key' => AuthenticationSettings::OAUTH_PROFILE_REQUIRED, 'value' => '1']);
        $this->put(route('admin.authentication.update'), [...$payload, 'oauth_profile_required' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['key' => AuthenticationSettings::OAUTH_PROFILE_REQUIRED, 'value' => '0']);
    }

    public function test_new_identity_waits_for_profile_completion_without_storing_provider_tokens(): void
    {
        $this->enableCompletion();
        $identity = $this->identity();
        $identity->token = 'private-provider-token';
        Socialite::fake('google', $identity);

        $this->get(route('oauth.callback', 'google'))->assertRedirect(route('oauth.profile.create'))
            ->assertSessionHas(PendingOAuthProfile::SESSION_KEY, fn (array $pending): bool => ! str_contains(json_encode($pending), 'private-provider-token'));
        $this->get(route('oauth.profile.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/CompleteOAuthProfile')->where('email', 'ada@example.com'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_completion_validates_and_records_signup_questions_and_uses_the_provider_email(): void
    {
        $this->enableCompletion();
        $form = Form::factory()->registration()->withFields([
            FormTemplates::field(FormFieldType::ShortText, 'company', 'Company', required: true),
        ])->create();
        Socialite::fake('google', $this->identity());
        $this->get(route('oauth.callback', 'google'));

        $this->post(route('oauth.profile.store'), [])->assertSessionHasErrors(['name', 'answers.company']);
        $this->assertDatabaseCount('users', 0);
        $this->post(route('oauth.profile.store'), ['name' => 'Ada Okafor', 'email' => 'attacker@example.com', 'answers' => ['company' => 'Example']])
            ->assertRedirect(route('dashboard'))->assertSessionMissing(PendingOAuthProfile::SESSION_KEY);

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Ada Okafor', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $submission = FormSubmission::query()->sole();
        $this->assertSame($form->id, $submission->form_id);
        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame(['company' => 'Example'], $submission->data);
        $this->post(route('oauth.profile.store'), ['name' => 'Replay'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_is_upgraded_only_after_completing_the_profile(): void
    {
        $this->enableCompletion();
        $guest = User::factory()->guest()->create();
        Socialite::fake('google', $this->identity());
        $this->actingAs($guest)->get(route('oauth.callback', 'google'));

        $this->assertTrue($guest->fresh()->is_guest);
        $this->post(route('oauth.profile.store'), ['name' => 'Ada'])->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['id' => $guest->id, 'is_guest' => false, 'email' => 'ada@example.com']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_expired_or_missing_identity_cannot_create_an_account(): void
    {
        $this->enableCompletion();
        $this->post(route('oauth.profile.store'), ['name' => 'Fake'])->assertRedirect(route('login'));
        Socialite::fake('google', $this->identity());
        $this->get(route('oauth.callback', 'google'));
        $this->travel(11)->minutes();

        $this->post(route('oauth.profile.store'), ['name' => 'Ada'])->assertRedirect(route('login'))
            ->assertSessionMissing(PendingOAuthProfile::SESSION_KEY);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_closing_registration_while_a_profile_is_pending_prevents_account_creation(): void
    {
        $this->enableCompletion();
        Socialite::fake('google', $this->identity());
        $this->get(route('oauth.callback', 'google'));
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');

        $this->post(route('oauth.profile.store'), ['name' => 'Ada'])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['oauth' => 'Registration is currently closed.']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_disabling_the_provider_while_pending_prevents_completion(): void
    {
        $this->enableCompletion();
        Socialite::fake('google', $this->identity());
        $this->get(route('oauth.callback', 'google'));
        OAuthProvider::query()->where('provider', 'google')->update(['is_enabled' => false]);

        $this->post(route('oauth.profile.store'), ['name' => 'Ada'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_linked_accounts_still_sign_in_without_profile_completion(): void
    {
        $this->enableCompletion();
        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create(['provider' => 'google', 'provider_user_id' => 'google-1']);
        Socialite::fake('google', $this->identity());

        $this->get(route('oauth.callback', 'google'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_pending_profile_cannot_be_completed_under_another_account_session(): void
    {
        $this->enableCompletion();
        Socialite::fake('google', $this->identity());
        $this->get(route('oauth.callback', 'google'));
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('oauth.profile.store'), ['name' => 'Ada'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_mobile_signin_requests_completion_before_issuing_any_token(): void
    {
        $this->enableCompletion();
        $this->mock(OAuthTokenResolver::class)->shouldReceive('resolve')->with('google', 'verified-provider-token')->andReturn($this->identity());

        $this->postJson(route('api.v1.auth.oauth.token', 'google'), ['access_token' => 'verified-provider-token', 'device_name' => 'Phone'])
            ->assertUnprocessable()->assertJsonPath('requires_profile_completion', true)->assertJsonPath('signupFields', []);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mobile_completion_validates_the_profile_and_returns_a_token(): void
    {
        $this->enableCompletion();
        $this->mock(OAuthTokenResolver::class)->shouldReceive('resolve')->andReturn($this->identity());

        $this->postJson(route('api.v1.auth.oauth.token', 'google'), ['access_token' => 'token', 'device_name' => 'Phone', 'name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('api.v1.auth.oauth.token', 'google'), ['access_token' => 'token', 'device_name' => 'Phone', 'name' => 'Ada'])
            ->assertOk()->assertJsonPath('user.email', 'ada@example.com');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Phone']);
    }

    private function enableCompletion(): void
    {
        Setting::store(AuthenticationSettings::OAUTH_PROFILE_REQUIRED, '1');
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'client', 'client_secret' => 'secret']);
    }

    private function identity(): SocialiteUser
    {
        return (new SocialiteUser)->setRaw(['email_verified' => true])
            ->map(['id' => 'google-1', 'name' => 'Ada', 'email' => 'ada@example.com', 'avatar' => null]);
    }
}
