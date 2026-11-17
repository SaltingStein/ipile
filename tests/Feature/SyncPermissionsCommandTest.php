<?php

namespace Tests\Feature;

use App\Services\Auth\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers `php artisan ipile:sync-permissions` and the seeder that shares its rules (PermissionRegistry::sync()).
 */
class SyncPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_permission_is_created_and_granted_to_its_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permissions::define('view_reports', 'See the reports pages', grantTo: ['admin', 'auditor']);

        $this->artisan('ipile:sync-permissions')
            ->expectsOutputToContain('view_reports')
            ->assertSuccessful();

        $this->assertTrue(Permission::findByName('view_reports', 'web')->exists);
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('view_reports'));
        $this->assertTrue(Role::findByName('auditor', 'web')->hasPermissionTo('view_reports'));
        $this->assertFalse(Role::findByName('member', 'web')->hasPermissionTo('view_reports'));
    }

    public function test_changes_made_by_admins_survive_a_sync(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::findByName('member', 'web')->revokePermissionTo('use_ai_assistant');
        Role::findByName('marketing', 'web')->givePermissionTo('manage_users');

        $this->artisan('ipile:sync-permissions')->expectsOutputToContain('Permissions are up to date.')->assertSuccessful();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(Role::findByName('member', 'web')->hasPermissionTo('use_ai_assistant'));
        $this->assertTrue(Role::findByName('marketing', 'web')->hasPermissionTo('manage_users'));
    }

    public function test_a_fresh_install_gets_every_default_grant(): void
    {
        $this->artisan('ipile:sync-permissions')->assertSuccessful();

        $this->assertSame(
            ['manage_blog', 'manage_campaigns', 'manage_forms', 'use_ai_assistant'],
            Role::findByName('marketing', 'web')->permissions->pluck('name')->sort()->values()->all(),
        );
    }
}
