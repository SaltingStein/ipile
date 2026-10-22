<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Replace the two-factor recovery codes.
 */
class RecoveryCodeController extends Controller
{
    /**
     * Issue a fresh set of recovery codes; the old ones stop working.
     */
    public function store(Request $request, TwoFactorAuthenticator $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->hasEnabledTwoFactorAuthentication(), 404);

        $twoFactor->regenerateRecoveryCodes($request->user());

        return back()->with('status', __('New recovery codes generated. The old codes no longer work.'));
    }
}
