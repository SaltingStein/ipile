<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Auth\TrustedDeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('canRegister', true)
            ->where('oauthProviders', []));
    }

    public function test_valid_credentials_sign_in_and_record_the_login_time(): void
    {
        $this->travelTo('2026-10-14 09:30:00');
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('2026-10-14 09:30:00', $user->fresh()->last_login_at->toDateTimeString());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertGuest();
    }

    public function test_suspended_account_cannot_sign_in(): void
    {
        $user = User::factory()->suspended()->create();

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'This account has been suspended.']);
        $this->assertGuest();
    }

    public function test_user_with_two_factor_is_sent_to_the_challenge_without_signing_in(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas(SessionAuthenticator::PENDING_USER, $user->id);
        $response->assertSessionHas(SessionAuthenticator::PENDING_REMEMBER, true);
        $this->assertGuest();
    }

    public function test_trusted_device_skips_the_two_factor_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->trustedDevices()->create(['token_hash' => hash('sha256', 'trusted-token'), 'expires_at' => now()->addDay()]);

        $response = $this->withCookie(TrustedDeviceManager::COOKIE, 'trusted-token')
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_trusted_device_still_requires_the_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->trustedDevices()->create(['token_hash' => hash('sha256', 'trusted-token'), 'expires_at' => now()->subMinute()]);

        $response = $this->withCookie(TrustedDeviceManager::COOKIE, 'trusted-token')
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_guest_account_may_sign_in_to_a_registered_account(): void
    {
        $guest = User::factory()->guest()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($guest)->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_ends_the_session(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }
}
