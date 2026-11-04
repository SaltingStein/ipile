<?php

namespace Database\Seeders;

use App\Services\Auth\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Baseline roles and permissions every Ìpìlẹ̀ project starts with, plus those registered with
 * `Permissions::define()`. Safe to run repeatedly: it only adds what is missing (see {@see PermissionRegistry::sync()}).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @deprecated Read {@see PermissionRegistry::names()}, which includes product permissions.
     *
     * @var list<string>
     */
    public const PERMISSIONS = PermissionRegistry::PERMISSIONS;

    /**
     * @deprecated Read {@see PermissionRegistry::roles()}, which includes product grants.
     *
     * @var array<string, list<string>>
     */
    public const ROLES = PermissionRegistry::ROLES;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistry::class)->sync();
    }
}
