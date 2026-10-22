<?php

namespace Tests\Feature\Middleware;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureTwoFactorIsConfiguredTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_of_a_required_role_without_two_factor_is_sent_to_security_settings(): void
    {
        $user = $this->adminWithRequiredTwoFactor(User::factory()->create());

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('security.show'));
        $response->assertSessionHas('status', 'Your role requires two-factor authentication. Set up an authenticator app to continue.');
    }

    public function test_member_of_a_required_role_with_two_factor_continues(): void
    {
        $user = $this->adminWithRequiredTwoFactor(User::factory()->withTwoFactor()->create());

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_users_outside_the_required_roles_continue(): void
    {
        Setting::store(AuthenticationSettings::TWO_FACTOR_REQUIRED_ROLES, json_encode(['admin']));

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }

    private function adminWithRequiredTwoFactor(User $user): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::store(AuthenticationSettings::TWO_FACTOR_REQUIRED_ROLES, json_encode(['admin']));

        return $user->assignRole('admin');
    }
}
