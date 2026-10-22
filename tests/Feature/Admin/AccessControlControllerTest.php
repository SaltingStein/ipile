<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_account_cannot_create_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->actingAs(User::factory()->guest()->create())
            ->post(route('admin.access.update'), ['role' => 'hijacked']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'hijacked']);
    }

    public function test_user_with_manage_access_can_create_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_access');

        $this->actingAs($manager)->post(route('admin.access.update'), ['role' => 'support', 'permission' => 'view_billing']);

        $this->assertTrue(Role::findByName('support')->hasPermissionTo('view_billing'));
    }

    public function test_user_with_manage_access_can_view_access_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_access');

        $response = $this->actingAs($manager)->get(route('admin.access'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AccessControl')
            ->where('permissions', collect(RolesAndPermissionsSeeder::PERMISSIONS)->sort()->values()->all())
            ->where('roles.0.name', 'super_admin')
            ->where('users.0.name', $manager->name));
    }

    public function test_user_with_manage_access_can_assign_role_to_a_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_access');
        $member = User::factory()->create();

        $this->actingAs($manager)->post(route('admin.access.update'), [
            'user_id' => $member->id,
            'role' => 'admin',
        ]);

        $this->assertTrue($member->fresh()->hasRole('admin'));
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $member->id,
            'role_id' => Role::findByName('admin')->id,
        ]);
    }
}
