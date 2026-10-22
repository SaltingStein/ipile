<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Auth\TrustedDeviceManager;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Second sign-in step for accounts with an authenticator app: a six-digit code or a recovery code.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Show the challenge, or send the visitor back to login when no sign-in is pending.
     */
    public function create(Request $request, SessionAuthenticator $authenticator, AuthenticationSettings $settings): Response|RedirectResponse
    {
        if ($authenticator->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'trustDeviceDays' => $settings->trustedDeviceDays(),
        ]);
    }

    /**
     * Verify the code and finish signing in.
     */
    public function store(
        Request $request,
        SessionAuthenticator $authenticator,
        TwoFactorAuthenticator $twoFactor,
        TrustedDeviceManager $trustedDevices,
    ): RedirectResponse {
        $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
            'trust_device' => ['boolean'],
        ]);

        $user = $authenticator->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => __('Your sign-in expired. Please sign in again.')]);
        }

        $throttleKey = 'two-factor-challenge:'.$user->getKey();
        $field = $request->filled('recovery_code') ? 'recovery_code' : 'code';

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $field => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey), 'minutes' => 1]),
            ]);
        }

        $isValid = $field === 'recovery_code'
            ? $twoFactor->useRecoveryCode($user, $request->string('recovery_code')->toString())
            : $twoFactor->verify($user, $request->string('code')->toString());

        if (! $isValid) {
            RateLimiter::hit($throttleKey);
            Security::recordFor($user, 'auth.two_factor_failed');

            throw ValidationException::withMessages([$field => __('The provided two-factor code was invalid.')]);
        }

        RateLimiter::clear($throttleKey);

        if ($request->boolean('trust_device')) {
            $trustedDevices->trust($user, $request);
        }

        return $authenticator->complete($request, $user, (bool) $request->session()->get(SessionAuthenticator::PENDING_REMEMBER, false));
    }
}
