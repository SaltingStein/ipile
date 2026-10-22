<?php

namespace Tests\Feature\Settings;

use App\Models\SocialAccount;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ConnectedAccountController and TrustedDeviceController.
 */
class ConnectedAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_a_password_can_disconnect_google(): void
    {
        $account = SocialAccount::factory()->create();

        $response = $this->confirmedAs($account->user)->delete(route('connected-accounts.destroy', 'google'));

        $response->assertSessionHas('status', 'Google account disconnected.');
        $this->assertModelMissing($account);
    }

    public function test_disconnecting_the_only_sign_in_method_is_refused(): void
    {
        $account = SocialAccount::factory()->for(User::factory()->withoutPassword())->create();

        $response = $this->confirmedAs($account->user)->delete(route('connected-accounts.destroy', 'google'));

        $response->assertSessionHasErrors(['oauth' => 'Set a password before disconnecting your only sign-in method.']);
        $this->assertModelExists($account);
    }

    public function test_unsupported_provider_returns_404(): void
    {
        $this->confirmedAs(User::factory()->create())
            ->delete(route('connected-accounts.destroy', 'myspace'))
            ->assertNotFound();
    }

    public function test_removing_trusted_devices_deletes_only_this_users_devices(): void
    {
        $user = User::factory()->create();
        TrustedDevice::factory()->for($user)->count(2)->create();
        $otherDevice = TrustedDevice::factory()->create();

        $this->confirmedAs($user)->delete(route('trusted-devices.destroy'));

        $this->assertDatabaseMissing('trusted_devices', ['user_id' => $user->id]);
        $this->assertModelExists($otherDevice);
    }

    private function confirmedAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
