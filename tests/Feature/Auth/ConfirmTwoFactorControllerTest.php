<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnforceSecurityStepUp;
use App\Models\User;
use App\Services\Security\FreshTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Covers the "Confirm it's you" page used before protected actions.
 */
class ConfirmTwoFactorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_asks_two_factor_accounts_for_a_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->get(route('security.confirm'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/ConfirmTwoFactor'));
    }

    public function test_accounts_without_two_factor_are_sent_to_set_it_up(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('security.confirm'));

        $response->assertRedirect(route('security.show'));
        $response->assertSessionHas('status', 'Set up an authenticator app to continue, then go back to what you were doing.');
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('security.confirm'))->assertRedirect(route('login'));
    }

    public function test_a_valid_code_confirms_and_returns_to_the_protected_page(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)
            ->withSession(['url.intended' => url('/wallet')])
            ->post(route('security.confirm.store'), ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)]);

        $response->assertRedirect(url('/wallet'));
        $response->assertSessionHas(FreshTwoFactor::SESSION_KEY);
        $response->assertSessionHas('auth.password_confirmed_at');
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'account.two_factor_confirmed']);
    }

    public function test_a_recovery_code_confirms_once(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->post(route('security.confirm.store'), ['recovery_code' => 'recovery-code-one']);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas(FreshTwoFactor::SESSION_KEY);
        $this->assertSame(['recovery-code-two'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_a_wrong_code_is_rejected_and_recorded(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->post(route('security.confirm.store'), ['code' => '000000']);

        $response->assertSessionHasErrors(['code' => 'The provided two-factor code was invalid.']);
        $response->assertSessionMissing(FreshTwoFactor::SESSION_KEY);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'auth.two_factor_failed']);
        $this->assertDatabaseMissing('security_events', ['type' => 'account.two_factor_confirmed']);
    }

    public function test_attempts_are_rate_limited(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        foreach (range(1, 6) as $attempt) {
            $this->post(route('security.confirm.store'), ['code' => '000000']);
        }

        $this->post(route('security.confirm.store'), ['code' => '000000'])->assertTooManyRequests();
    }

    public function test_confirming_clears_an_aegis_password_recheck(): void
    {
        $this->freezeTime();
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user)->withSession([EnforceSecurityStepUp::SESSION_KEY => now()->getTimestamp()]);

        $this->get(route('dashboard'))->assertRedirect(route('password.confirm'));
        $this->get(route('security.confirm'))->assertOk();
        $this->post(route('security.confirm.store'), ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)]);

        $this->get(route('dashboard'))->assertOk();
    }
}
