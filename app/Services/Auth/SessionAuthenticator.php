<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Finishes a browser sign-in after the user's identity is proven (password, OAuth or email link), sending users
 * with an authenticator app to the two-factor challenge first.
 */
class SessionAuthenticator
{
    public const PENDING_USER = 'login.id';

    public const PENDING_REMEMBER = 'login.remember';

    public function __construct(private TrustedDeviceManager $trustedDevices) {}

    /**
     * Start a session, or park the user at the two-factor challenge.
     */
    public function login(Request $request, User $user, bool $remember): RedirectResponse
    {
        if ($user->isSuspended()) {
            return redirect()->route('login')->withErrors(['email' => __('This account has been suspended.')]);
        }

        if ($user->hasEnabledTwoFactorAuthentication() && ! $this->trustedDevices->isTrusted($user, $request)) {
            $request->session()->put([
                self::PENDING_USER => $user->getKey(),
                self::PENDING_REMEMBER => $remember,
            ]);

            return redirect()->route('two-factor.login');
        }

        return $this->complete($request, $user, $remember);
    }

    /**
     * Log the user in and send them to where they were going.
     */
    public function complete(Request $request, User $user, bool $remember): RedirectResponse
    {
        $request->session()->forget([self::PENDING_USER, self::PENDING_REMEMBER]);

        Auth::login($user, $remember);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * The user waiting at the two-factor challenge, if any.
     */
    public function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get(self::PENDING_USER);

        return $userId === null ? null : User::query()->find($userId);
    }
}
