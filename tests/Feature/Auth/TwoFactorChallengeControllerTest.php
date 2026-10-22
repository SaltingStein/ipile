<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Auth\TrustedDeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorChallengeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenge_page_redirects_to_login_without_a_pending_sign_in(): void
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }

    public function test_challenge_page_renders_for_a_pending_sign_in(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->withSession([SessionAuthenticator::PENDING_USER => $user->id])->get(route('two-factor.login'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorChallenge')
            ->where('trustDeviceDays', 30));
    }

    public function test_valid_authenticator_code_completes_sign_in(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['code' => $this->currentCode($user)]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionMissing(SessionAuthenticator::PENDING_USER);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_code_is_rejected(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['code' => '000000']);

        $response->assertSessionHasErrors(['code' => 'The provided two-factor code was invalid.']);
        $this->assertGuest();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $code = $this->currentCode($user);
        $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['code' => $code]);
        $this->post(route('logout'));

        $response = $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['code' => $code]);

        $response->assertSessionHasErrors(['code' => 'The provided two-factor code was invalid.']);
        $this->assertGuest();
    }

    public function test_recovery_code_signs_in_once_and_is_removed(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['recovery-code-two'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_trusting_the_device_stores_a_trusted_device_and_cookie(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->withPendingSignIn($user)->post(route('two-factor.login.store'), [
            'code' => $this->currentCode($user),
            'trust_device' => true,
        ]);

        $response->assertCookie(TrustedDeviceManager::COOKIE);
        $this->assertDatabaseCount('trusted_devices', 1);
        $this->assertDatabaseHas('trusted_devices', ['user_id' => $user->id]);
    }

    public function test_challenge_is_throttled_after_five_wrong_codes(): void
    {
        $this->freezeTime();
        $user = User::factory()->withTwoFactor()->create();

        foreach (range(1, 5) as $attempt) {
            $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['code' => '000000']);
        }
        $response = $this->withPendingSignIn($user)->post(route('two-factor.login.store'), ['code' => $this->currentCode($user)]);

        $response->assertSessionHasErrors(['code' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertGuest();
    }

    private function withPendingSignIn(User $user): static
    {
        return $this->withSession([
            SessionAuthenticator::PENDING_USER => $user->id,
            SessionAuthenticator::PENDING_REMEMBER => false,
        ]);
    }

    private function currentCode(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
    }
}
