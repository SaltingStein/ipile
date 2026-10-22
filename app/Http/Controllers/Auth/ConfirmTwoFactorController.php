<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\FreshTwoFactor;
use App\Services\Security\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enter a fresh code from the authenticator app before a protected action (`security.confirm` middleware,
 * {@see Security::requireFreshTwoFactor()}, or a {@see Security::check()} challenge), then go back to it.
 */
class ConfirmTwoFactorController extends Controller
{
    /**
     * Show the form. Accounts without two-factor are sent to set up an authenticator app first.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('security.show')
                ->with('status', __('Set up an authenticator app to continue, then go back to what you were doing.'));
        }

        return Inertia::render('Auth/ConfirmTwoFactor');
    }

    /**
     * Check the code, remember the confirmation in this browser and continue to the protected page.
     */
    public function store(Request $request, TwoFactorAuthenticator $twoFactor, FreshTwoFactor $freshTwoFactor): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('security.show');
        }

        $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $field = $request->filled('recovery_code') ? 'recovery_code' : 'code';
        $isValid = $field === 'recovery_code'
            ? $twoFactor->useRecoveryCode($user, $request->string('recovery_code')->toString())
            : $twoFactor->verify($user, $request->string('code')->toString());

        if (! $isValid) {
            Security::recordFor($user, 'auth.two_factor_failed', ['step' => 'confirm']);

            throw ValidationException::withMessages([$field => __('The provided two-factor code was invalid.')]);
        }

        $freshTwoFactor->markConfirmed($request);
        Security::recordFor($user, 'account.two_factor_confirmed', ['method' => $field === 'recovery_code' ? 'recovery_code' : 'authenticator']);

        return redirect()->intended(route('dashboard'));
    }
}
