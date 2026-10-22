<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Security\Security;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;

/**
 * Feeds Laravel's own authentication events to Aegis. Each `handle*` method is discovered by its type hint.
 * Events raised by the app itself (password changed, two-factor off, team changes…) are recorded where they happen.
 */
class RecordSecurityEvents
{
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            Security::recordFor($event->user, 'auth.login', ['remember' => $event->remember]);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Security::recordFor($event->user, 'auth.logout');
        }
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');
        $user = is_string($email) ? User::query()->where('email', $email)->first() : null;

        Security::recordFor($user, 'auth.lockout');
    }

    public function handleRegistered(Registered $event): void
    {
        if ($event->user instanceof User) {
            Security::recordFor($event->user, 'account.registered');
        }
    }

    public function handleVerified(Verified $event): void
    {
        if ($event->user instanceof User) {
            Security::recordFor($event->user, 'account.email_verified');
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Security::recordFor($event->user, 'auth.password_reset');
        }
    }
}
