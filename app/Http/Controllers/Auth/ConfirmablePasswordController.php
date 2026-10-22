<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\FreshTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Re-enter the password before sensitive pages (Laravel's `password.confirm` middleware).
 */
class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirmation form. OAuth-only accounts have no password to confirm and continue directly.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPassword() && ! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->markConfirmed($request);
        }

        return Inertia::render('Auth/ConfirmPassword', [
            'requiresAuthenticator' => $user->hasEnabledTwoFactorAuthentication(),
        ]);
    }

    /**
     * Check the current trusted evidence and continue to the intended page.
     */
    public function store(Request $request, TwoFactorAuthenticator $twoFactor, FreshTwoFactor $freshTwoFactor): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->validate([
                'code' => ['nullable', 'string', 'required_without:recovery_code'],
                'recovery_code' => ['nullable', 'string'],
            ]);

            $field = $request->filled('recovery_code') ? 'recovery_code' : 'code';
            $isValid = $field === 'recovery_code'
                ? $twoFactor->useRecoveryCode($user, $request->string('recovery_code')->toString())
                : $twoFactor->verify($user, $request->string('code')->toString());

            if (! $isValid) {
                throw ValidationException::withMessages([
                    $field => __('The provided two-factor code was invalid.'),
                ]);
            }

            // The code is fresh, so protected actions (security.confirm) need not ask again straight away.
            $freshTwoFactor->markConfirmed($request);

            return $this->markConfirmed($request);
        }

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $request->string('password')->toString()])) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        return $this->markConfirmed($request);
    }

    private function markConfirmed(Request $request): RedirectResponse
    {
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('dashboard'));
    }
}
