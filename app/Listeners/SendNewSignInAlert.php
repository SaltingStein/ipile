<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\DeviceName;
use App\Services\Notifications\Notify;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Alerts the account owner when a browser that has never signed in to the account before does so.
 * Each browser remembers the accounts it has used through a long-lived (encrypted) cookie.
 */
class SendNewSignInAlert
{
    public const COOKIE_PREFIX = 'known_device_';

    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($event->guard !== 'web' || ! $user instanceof User || $user->is_guest || $user->email === null) {
            return;
        }

        $cookie = self::COOKIE_PREFIX.$user->getKey();

        if ($this->request->cookie($cookie) !== null) {
            return;
        }

        Cookie::queue($cookie, '1', self::COOKIE_MINUTES);

        // The first sign-in (straight after sign-up or setup) is expected, so it does not alert.
        if ($user->last_login_at === null) {
            return;
        }

        Notify::event('security.new_sign_in')->to($user)->with([
            'device' => DeviceName::fromUserAgent($this->request->userAgent()),
            'ip_address' => $this->request->ip(),
            'link' => route('security.show'),
        ])->send();
    }
}
