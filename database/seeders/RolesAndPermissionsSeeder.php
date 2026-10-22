<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Baseline roles and permissions every Ìpìlẹ̀ project starts with. Safe to run repeatedly.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const PERMISSIONS = [
        'edit_legal_content',
        'publish_legal_content',
        'manage_access',
        'manage_users',
        'manage_settings',
        'manage_integrations',
        'manage_api_docs',
        'suspend_users',
        'view_billing',
        'view_security',
        'manage_security',
        'manage_forms',
        'manage_blog',
        'manage_campaigns',
        'manage_translations',
        // Staff modes of the site assistant (App\Services\Assistant\AssistantIntent), once switched on in Admin → Site assistant.
        'use_assistant_guidance',
        'use_assistant_analytics',
    ];

    /**
     * Role => permissions. `super_admin` passes every check through a gate, so it needs no direct grants.
     *
     * @var array<string, list<string>>
     */
    public const ROLES = [
        'super_admin' => [],
        'admin' => ['manage_users', 'suspend_users', 'view_billing', 'edit_legal_content', 'publish_legal_content', 'manage_api_docs', 'view_security', 'use_assistant_guidance', 'use_assistant_analytics'],
        // People who run campaigns, the blog and site forms. Assign it in Admin → Roles & permissions.
        'marketing' => ['manage_campaigns', 'manage_blog', 'manage_forms'],
        'member' => [],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
    }
}
