<?php

namespace App\Services\Auth;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Every full account gets the `member` role, so permissions meant for everyone (such as `use_ai_assistant`) can be
 * granted and taken away in Admin → Roles & permissions like any other. Guests get no role. Accounts that already
 * have a role are left alone, and nothing happens before the roles are seeded.
 */
class DefaultRole
{
    public const ROLE = 'member';

    public function assignTo(User $user): void
    {
        if ($user->is_guest || $user->roles()->exists()) {
            return;
        }

        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();

        if ($role !== null) {
            $user->assignRole($role);
        }
    }
}
