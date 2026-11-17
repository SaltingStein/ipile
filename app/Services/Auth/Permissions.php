<?php

namespace App\Services\Auth;

/**
 * Add your own permissions from a service provider's boot(), instead of editing the seeder:
 *
 *     Permissions::define('view_reports', 'See the reports pages', grantTo: ['admin']);
 *     Permissions::define('manage_invoices', 'Manage invoices in the admin area', grantTo: ['admin'], admin: true);
 *
 * Then run `php artisan ipile:sync-permissions` (on every deploy, next to `migrate --force`). Protect routes
 * with `->middleware('permission:view_reports')`; a test fails if a route names a permission nobody registered.
 *
 * @api
 */
final class Permissions
{
    /**
     * @param  list<string>  $grantTo  Roles given the permission when it is first created. Admins can change this later.
     * @param  bool  $admin  True when it opens one of your admin pages, so its holders can reach the admin overview (/admin).
     */
    public static function define(string $name, string $description = '', array $grantTo = [], bool $admin = false): void
    {
        app(PermissionRegistry::class)->register($name, $description, $grantTo, $admin);
    }
}
