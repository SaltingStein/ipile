<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\TrustedDeviceManager;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\FreshTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Set up, confirm and remove the authenticator app.
 */
class TwoFactorAuthenticationController extends Controller
{
    /**
     * Generate a secret and show the QR code (setup is pending until confirmed).
     */
    public function store(Request $request, TwoFactorAuthenticator $twoFactor): RedirectResponse
    {
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            $twoFactor->beginSetup($request->user());
        }

        return back();
    }

    /**
     * Confirm setup with a code from the app. Existing trusted devices are revoked.
     */
    public function confirm(Request $request, TwoFactorAuthenticator $twoFactor, TrustedDeviceManager $trustedDevices, FreshTwoFactor $freshTwoFactor): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        if (! $twoFactor->confirmSetup($request->user(), $request->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => __('The provided two-factor code was invalid.')]);
        }

        $trustedDevices->revokeAll($request->user());
        // The setup code was fresh, so someone sent here by a protected action can go straight back to it.
        $freshTwoFactor->markConfirmed($request);

        return back()->with('status', __('Two-factor authentication is on. Save your recovery codes somewhere safe.'));
    }

    /**
     * Turn two-factor authentication off, or cancel a pending setup.
     */
    public function destroy(
        Request $request,
        TwoFactorAuthenticator $twoFactor,
        TrustedDeviceManager $trustedDevices,
        AuthenticationSettings $settings,
    ): RedirectResponse {
        $user = $request->user();
        $requiredRoles = $settings->twoFactorRequiredRoles();

        if ($user->hasEnabledTwoFactorAuthentication() && $requiredRoles !== [] && $user->hasAnyRole($requiredRoles)) {
            throw ValidationException::withMessages(['two_factor' => __('Your role requires two-factor authentication, so it cannot be turned off.')]);
        }

        $twoFactor->disable($user);
        $trustedDevices->revokeAll($user);

        return back()->with('status', __('Two-factor authentication is off.'));
    }
}
