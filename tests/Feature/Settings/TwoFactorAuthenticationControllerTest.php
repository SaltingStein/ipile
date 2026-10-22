<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\FreshTwoFactor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Covers TwoFactorAuthenticationController and RecoveryCodeController.
 */
class TwoFactorAuthenticationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_setup_creates_a_pending_secret(): void
    {
        $user = User::factory()->create();

        $this->confirmedAs($user)->post(route('two-factor.enable'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_two_factor_actions_require_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_valid_code_confirms_setup_issues_recovery_codes_and_revokes_trusted_devices(): void
    {
        $user = User::factory()->create();
        app(TwoFactorAuthenticator::class)->beginSetup($user);
        TrustedDevice::factory()->for($user)->create();

        $response = $this->confirmedAs($user)->post(route('two-factor.confirm'), [
            'code' => (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret),
        ]);

        $response->assertSessionHas('status', 'Two-factor authentication is on. Save your recovery codes somewhere safe.');
        // Someone sent to set up two-factor by a protected action can go straight back to it.
        $response->assertSessionHas(FreshTwoFactor::SESSION_KEY);
        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertCount(8, $user->two_factor_recovery_codes);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_invalid_code_does_not_confirm_setup(): void
    {
        $user = User::factory()->create();
        app(TwoFactorAuthenticator::class)->beginSetup($user);

        $response = $this->confirmedAs($user)->post(route('two-factor.confirm'), ['code' => '000000']);

        $response->assertSessionHasErrors(['code' => 'The provided two-factor code was invalid.']);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $stored = $user->newQuery()->toBase()->where('id', $user->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);

        $this->assertNotSame($user->two_factor_secret, $stored->two_factor_secret);
        $this->assertStringNotContainsString('recovery-code-one', $stored->two_factor_recovery_codes);
    }

    public function test_turning_off_removes_two_factor(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->confirmedAs($user)->delete(route('two-factor.disable'));

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_member_of_a_required_role_cannot_turn_two_factor_off(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::store(AuthenticationSettings::TWO_FACTOR_REQUIRED_ROLES, json_encode(['admin']));
        $user = User::factory()->withTwoFactor()->create()->assignRole('admin');

        $response = $this->confirmedAs($user)->delete(route('two-factor.disable'));

        $response->assertSessionHasErrors(['two_factor' => 'Your role requires two-factor authentication, so it cannot be turned off.']);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_regenerating_recovery_codes_replaces_the_old_ones(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->confirmedAs($user)->post(route('two-factor.recovery-codes'));

        $codes = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount(8, $codes);
        $this->assertNotContains('recovery-code-one', $codes);
    }

    public function test_regenerating_recovery_codes_without_two_factor_returns_404(): void
    {
        $this->confirmedAs(User::factory()->create())
            ->post(route('two-factor.recovery-codes'))
            ->assertNotFound();
    }

    private function confirmedAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
