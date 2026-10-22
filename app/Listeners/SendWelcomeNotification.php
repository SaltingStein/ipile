<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Notifications\Notify;
use Illuminate\Auth\Events\Registered;

/**
 * Welcomes new accounts from email registration and social sign-up. Guests have no address, so they are skipped.
 */
class SendWelcomeNotification
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->is_guest || $user->email === null) {
            return;
        }

        Notify::event('auth.welcome')->to($user)->with(['link' => route('dashboard')])->send();
    }
}
