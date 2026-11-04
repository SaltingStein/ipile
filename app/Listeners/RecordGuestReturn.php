<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\GuestAccounts;
use Illuminate\Auth\Events\Login;

/**
 * Guests are signed back in by their "remember me" cookie rather than a sign-in form, so this keeps their
 * `last_login_at` current. Inactive guest accounts are deleted from it ({@see GuestAccounts}).
 * Written at most once an hour per guest.
 */
class RecordGuestReturn
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->is_guest) {
            return;
        }

        if ($user->last_login_at === null || $user->last_login_at->lt(now()->subHour())) {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        }
    }
}
