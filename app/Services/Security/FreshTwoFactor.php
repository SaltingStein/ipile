<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * "Did this browser enter a code from the authenticator app in the last few minutes?" Used by the
 * `security.confirm` route middleware, {@see Security::requireFreshTwoFactor()} and decisions that ask for a
 * fresh code. Confirmations are kept in the session, so a new browser always asks again.
 *
 * Accounts without two-factor are sent to set up an authenticator app first (`setup`, the default), or asked for
 * their password instead (`password`), as the route chooses.
 */
class FreshTwoFactor
{
    public const SESSION_KEY = 'security.two_factor_confirmed_at';

    public const DEFAULT_MINUTES = 10;

    public const FALLBACK_SETUP = 'setup';

    public const FALLBACK_PASSWORD = 'password';

    public function confirmedWithin(int $minutes = self::DEFAULT_MINUTES, ?Request $request = null): bool
    {
        $request ??= request();
        $confirmedAt = $request->hasSession() ? (int) $request->session()->get(self::SESSION_KEY, 0) : 0;

        return $confirmedAt > 0 && now()->getTimestamp() - $confirmedAt <= $minutes * 60;
    }

    /**
     * Record that this browser just entered a valid authenticator or recovery code. A fresh code is stronger than a
     * password, so it also counts as a password confirmation (clearing an Aegis password re-check).
     */
    public function markConfirmed(?Request $request = null): void
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        $request->session()->passwordConfirmed();
    }

    /**
     * Carry on only when the person confirmed recently enough; otherwise throw the challenge that sends them to do it.
     *
     * @param  'setup'|'password'  $fallback  What accounts without two-factor do.
     *
     * @throws SecurityChallengeRequired
     * @throws AuthenticationException
     */
    public function ensure(?User $user, int $minutes = self::DEFAULT_MINUTES, string $fallback = self::FALLBACK_SETUP, ?Request $request = null): void
    {
        $request ??= request();

        if (! in_array($fallback, [self::FALLBACK_SETUP, self::FALLBACK_PASSWORD], true)) {
            throw new InvalidArgumentException("Unknown fresh two-factor fallback [{$fallback}]; use [setup] or [password].");
        }

        if ($minutes < 1) {
            throw new InvalidArgumentException('The fresh two-factor window must be at least one minute.');
        }

        if ($user === null) {
            throw new AuthenticationException;
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if ($this->confirmedWithin($minutes, $request)) {
                return;
            }

            throw new SecurityChallengeRequired(__('For your security, enter a code from your authenticator app to continue.'), route('security.confirm'));
        }

        if ($fallback === self::FALLBACK_PASSWORD) {
            $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;

            if ($confirmedAt > 0 && now()->getTimestamp() - $confirmedAt <= $minutes * 60) {
                return;
            }

            throw new SecurityChallengeRequired(__('For your security, confirm your password to continue.'), route('password.confirm'));
        }

        throw new SecurityChallengeRequired(__('For your security, set up an authenticator app to continue.'), route('security.confirm'));
    }
}
