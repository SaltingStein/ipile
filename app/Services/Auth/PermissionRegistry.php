<?php

namespace App\Services\Auth;

use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every permission and built-in role the app knows about: Ìpìlẹ̀'s own, then those added from service
 * providers with {@see Permissions::define()}. `php artisan ipile:sync-permissions` (and the
 * `RolesAndPermissionsSeeder`) creates what is missing in the database.
 *
 * Default grants are applied when a permission or role is first created. After that, admins own the
 * assignments in Admin → Roles & permissions: syncing never removes a grant and never restores one an
 * admin removed.
 */
class PermissionRegistry
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
        // The site assistant itself (Admin → Site assistant). On every built-in role, so remove it from a role to narrow access.
        'use_ai_assistant',
        // Staff modes of the site assistant (App\Services\Assistant\AssistantIntent), once switched on in Admin → Site assistant.
        'use_assistant_guidance',
        'use_assistant_analytics',
        // Admin → Site assistant → Activity: every assistant request, with what the person was allowed at the time.
        'view_ai_activity',
    ];

    /**
     * Role => permissions. `super_admin` passes every check through a gate, so it needs no direct grants.
     *
     * @var array<string, list<string>>
     */
    public const ROLES = [
        'super_admin' => [],
        'admin' => ['manage_users', 'suspend_users', 'view_billing', 'edit_legal_content', 'publish_legal_content', 'manage_api_docs', 'view_security', 'use_assistant_guidance', 'use_assistant_analytics', 'use_ai_assistant', 'view_ai_activity'],
        // People who run campaigns, the blog and site forms. Assign it in Admin → Roles & permissions.
        'marketing' => ['manage_campaigns', 'manage_blog', 'manage_forms', 'use_ai_assistant'],
        // Given to every full account (App\Services\Auth\DefaultRole).
        'member' => ['use_ai_assistant'],
    ];

    /**
     * Ìpìlẹ̀ permissions that open at least one admin page (the same pages as `resources/js/adminNavigation.ts`).
     *
     * @var list<string>
     */
    public const ADMIN_PERMISSIONS = [
        'manage_settings',
        'edit_legal_content',
        'publish_legal_content',
        'manage_access',
        'manage_integrations',
        'view_security',
        'manage_security',
        'manage_forms',
        'manage_blog',
        'manage_campaigns',
        'manage_translations',
        'view_ai_activity',
    ];

    /**
     * @var array<string, array{description: string, grant_to: list<string>, admin: bool}>
     */
    private array $registered = [];

    /**
     * @param  list<string>  $grantTo  Roles that get the permission when it is first created (created if missing).
     * @param  bool  $admin  Whether it opens one of your admin pages, so its holders can reach the admin overview.
     *
     * @throws InvalidArgumentException when the permission is already known
     */
    public function register(string $name, string $description = '', array $grantTo = [], bool $admin = false): void
    {
        if ($this->has($name)) {
            throw new InvalidArgumentException("Permission [{$name}] is already registered.");
        }

        $this->registered[$name] = ['description' => $description, 'grant_to' => array_values($grantTo), 'admin' => $admin];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [...self::PERMISSIONS, ...array_keys($this->registered)];
    }

    public function has(string $name): bool
    {
        return in_array($name, self::PERMISSIONS, true) || array_key_exists($name, $this->registered);
    }

    public function description(string $name): string
    {
        return $this->registered[$name]['description'] ?? '';
    }

    /**
     * Role => the permissions it gets by default, including those added with `grantTo`.
     *
     * @return array<string, list<string>>
     */
    public function roles(): array
    {
        $roles = self::ROLES;

        foreach ($this->registered as $name => $definition) {
            foreach ($definition['grant_to'] as $role) {
                $roles[$role][] = $name;
            }
        }

        return $roles;
    }

    /**
     * Every permission that opens an admin page; holding any of them opens the admin overview.
     *
     * @return list<string>
     */
    public function adminPermissions(): array
    {
        return [...self::ADMIN_PERMISSIONS, ...array_keys(array_filter($this->registered, fn (array $definition) => $definition['admin']))];
    }

    /**
     * Create missing permissions and roles, and give each new one its default grants. Safe to run on every deploy.
     *
     * @return array{permissions: list<string>, roles: list<string>, grants: list<string>} what was created, grants as "role: permission"
     */
    public function sync(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $created = ['permissions' => [], 'roles' => [], 'grants' => []];

        foreach ($this->names() as $name) {
            if (Permission::query()->where(['name' => $name, 'guard_name' => 'web'])->doesntExist()) {
                Permission::create(['name' => $name, 'guard_name' => 'web']);
                $created['permissions'][] = $name;
            }
        }

        foreach ($this->roles() as $roleName => $permissions) {
            $role = Role::query()->where(['name' => $roleName, 'guard_name' => 'web'])->first();
            $isNewRole = $role === null;
            $role ??= Role::create(['name' => $roleName, 'guard_name' => 'web']);

            if ($isNewRole) {
                $created['roles'][] = $roleName;
            }

            foreach ($permissions as $permission) {
                if (($isNewRole || in_array($permission, $created['permissions'], true)) && ! $role->hasPermissionTo($permission, 'web')) {
                    $role->givePermissionTo($permission);
                    $created['grants'][] = "{$roleName}: {$permission}";
                }
            }
        }

        $registrar->forgetCachedPermissions();

        return $created;
    }
}
