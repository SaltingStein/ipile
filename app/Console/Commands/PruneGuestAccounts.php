<?php

namespace App\Console\Commands;

use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\GuestAccounts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deletes guest accounts nobody has used for the period set in Admin → Sign-in & 2FA. Does nothing while that period
 * is 0 (the default), unless --days is given.
 */
#[Signature('guests:prune {--days= : Delete guests unused for this many days, instead of the dashboard setting}')]
#[Description('Delete guest accounts unused for longer than the guest retention period (Admin → Sign-in & 2FA)')]
class PruneGuestAccounts extends Command
{
    public function handle(GuestAccounts $guests, AuthenticationSettings $settings): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : $settings->guestRetentionDays();

        if ($days < 1) {
            $this->info('Guest accounts are kept: no retention period is set.');

            return self::SUCCESS;
        }

        $this->info("Deleted {$guests->pruneInactive($days)} guest accounts unused for {$days} days or more.");

        return self::SUCCESS;
    }
}
