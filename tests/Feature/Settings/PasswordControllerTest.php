<?php

namespace Tests\Feature\Settings;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_changes_with_the_correct_current_password(): void
    {
        $user = User::factory()->create();
        TrustedDevice::factory()->for($user)->create();

        $response = $this->confirmedAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $response->assertSessionHas('status', 'Password saved. Other devices have been signed out.');
        $this->assertTrue(Hash::check('new-secret123', $user->fresh()->password));
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->confirmedAs($user)->put(route('password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $response->assertSessionHasErrors(['current_password' => 'The password is incorrect.']);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_account_without_a_password_can_set_one_without_a_current_password(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $response = $this->confirmedAs($user)->put(route('password.update'), [
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secret123', $user->fresh()->password));
    }

    private function confirmedAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
