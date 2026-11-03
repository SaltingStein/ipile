<?php

use App\Models\User;
use App\Services\Auth\DefaultRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The site assistant now needs the `use_ai_assistant` permission (CORE-910). To keep everyone who had it, existing
 * sites give the permission to the built-in roles and the `member` role to every full account that has no role.
 * Fresh installs get the same from RolesAndPermissionsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Roles are created by the seeder or the /setup page; until then there is nothing to update.
        if (! Role::query()->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('use_ai_assistant', 'web');
        Role::findOrCreate(DefaultRole::ROLE, 'web');

        foreach (['admin', 'marketing', DefaultRole::ROLE] as $name) {
            Role::query()->where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        User::query()->where('is_guest', false)->whereDoesntHave('roles')
            ->chunkById(500, fn ($users) => $users->each(fn (User $user) => app(DefaultRole::class)->assignTo($user)));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Kept: removing the permission would take the assistant away from everyone.
    }
};
