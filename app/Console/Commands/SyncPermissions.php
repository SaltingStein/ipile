<?php

namespace App\Console\Commands;

use App\Services\Auth\PermissionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates the permissions and roles registered in code (Ìpìlẹ̀'s and `Permissions::define()`) that the database
 * does not have yet. Run it on every deploy, after `migrate --force`. It never removes a permission or a grant,
 * so changes made in Admin → Roles & permissions survive.
 */
#[Signature('ipile:sync-permissions')]
#[Description('Create missing permissions and roles, with their default grants (never removes anything)')]
class SyncPermissions extends Command
{
    public function handle(PermissionRegistry $registry): int
    {
        $created = $registry->sync();

        if ($created === ['permissions' => [], 'roles' => [], 'grants' => []]) {
            $this->components->info('Permissions are up to date.');

            return self::SUCCESS;
        }

        foreach (['permissions' => 'Permissions created', 'roles' => 'Roles created', 'grants' => 'Granted'] as $key => $label) {
            if ($created[$key] !== []) {
                $this->components->twoColumnDetail("<fg=green>{$label}</>", implode(', ', $created[$key]));
            }
        }

        return self::SUCCESS;
    }
}
