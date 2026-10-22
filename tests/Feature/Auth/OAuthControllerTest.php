<?php

namespace Tests\Feature\Auth;

use App\Models\OAuthProvider;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\SessionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_returns_404_when_google_is_not_enabled(): void
    {
        $this->get(route('oauth.redirect', 'google'))->assertNotFound();
    }

    public function test_redirect_returns_404_for_an_unsupported_provider(): void
    {
        $this->enableGoogle();

        $this->get(route('oauth.redirect', 'myspace'))->assertNotFound();
    }

    public function test_redirect_sends_the_browser_to_google_when_enabled(): void
    {
        $this->enableGoogle();
        Socialite::fake('google');

        $this->get(route('oauth.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_callback_creates_a_verified_account_and_signs_in(): void
    {
        $this->enableGoogle();
        Socialite::fake('google', $this->googleUser('google-1', 'ada@example.com'));

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ada Obi', $user->name);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-1']);
    }

    public function test_callback_leaves_the_email_unverified_when_google_does_not_confirm_it(): void
    {
        $this->enableGoogle();
        Socialite::fake('google', $this->googleUser('google-1', 'ada@example.com', emailVerified: false));

        $this->get(route('oauth.callback', 'google'));

        $this->assertNull(User::query()->where('email', 'ada@example.com')->value('email_verified_at'));
    }

    public function test_callback_signs_in_the_already_linked_account(): void
    {
        $this->enableGoogle();
        $account = SocialAccount::factory()->create(['provider_user_id' => 'google-1']);
        Socialite::fake('google', $this->googleUser('google-1', $account->email));

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($account->user);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_upgrades_the_signed_in_guest_in_place(): void
    {
        $this->enableGoogle();
        $guest = User::factory()->guest()->create();
        Socialite::fake('google', $this->googleUser('google-1', 'ada@example.com'));

        $this->actingAs($guest)->get(route('oauth.callback', 'google'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $guest->id, 'email' => 'ada@example.com', 'is_guest' => false]);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $guest->id, 'provider_user_id' => 'google-1']);
    }

    public function test_callback_refuses_an_email_that_belongs_to_another_account(): void
    {
        $this->enableGoogle();
        User::factory()->create(['email' => 'ada@example.com']);
        Socialite::fake('google', $this->googleUser('google-1', 'ada@example.com'));

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'oauth' => 'An account with this email already exists. Sign in with your password, then connect Google from your security settings.',
        ]);
        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_callback_links_google_to_the_signed_in_registered_user(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create();
        Socialite::fake('google', $this->googleUser('google-1', 'different@example.com'));

        $response = $this->actingAs($user)->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('security.show'));
        $response->assertSessionHas('status', 'Google account connected.');
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider_user_id' => 'google-1']);
    }

    public function test_callback_refuses_to_link_a_google_account_owned_by_another_user(): void
    {
        $this->enableGoogle();
        $owner = SocialAccount::factory()->create(['provider_user_id' => 'google-1']);
        $user = User::factory()->create();
        Socialite::fake('google', $this->googleUser('google-1', $owner->email));

        $response = $this->actingAs($user)->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('security.show'));
        $response->assertSessionHasErrors(['oauth' => 'This Google account is already connected to another user.']);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id]);
    }

    public function test_callback_sends_a_two_factor_user_to_the_challenge(): void
    {
        $this->enableGoogle();
        $user = User::factory()->withTwoFactor()->create();
        SocialAccount::factory()->for($user)->create(['provider_user_id' => 'google-1']);
        Socialite::fake('google', $this->googleUser('google-1', $user->email));

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas(SessionAuthenticator::PENDING_USER, $user->id);
        $this->assertGuest();
    }

    public function test_callback_refuses_a_suspended_account(): void
    {
        $this->enableGoogle();
        $account = SocialAccount::factory()->for(User::factory()->suspended())->create(['provider_user_id' => 'google-1']);
        Socialite::fake('google', $this->googleUser('google-1', $account->email));

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['email' => 'This account has been suspended.']);
        $this->assertGuest();
    }

    public function test_callback_does_not_create_accounts_when_registration_is_closed(): void
    {
        $this->enableGoogle();
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');
        Socialite::fake('google', $this->googleUser('google-1', 'ada@example.com'));

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertSessionHasErrors(['oauth' => 'Registration is currently closed.']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_reports_a_failed_or_cancelled_google_sign_in(): void
    {
        $this->enableGoogle();
        Socialite::fake('google', fn () => throw new InvalidStateException);

        $response = $this->get(route('oauth.callback', 'google'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['oauth' => 'Google sign-in was cancelled or failed. Please try again.']);
    }

    private function enableGoogle(): void
    {
        OAuthProvider::create([
            'provider' => 'google',
            'is_enabled' => true,
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
        ]);
    }

    private function googleUser(string $id, string $email, bool $emailVerified = true): SocialiteUser
    {
        return (new SocialiteUser)
            ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified])
            ->map([
                'id' => $id,
                'name' => 'Ada Obi',
                'email' => $email,
                'avatar' => 'https://example.com/avatar.png',
            ]);
    }
}
