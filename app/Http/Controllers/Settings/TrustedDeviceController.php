<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\TrustedDeviceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Forget browsers that were trusted to skip the two-factor challenge.
 */
class TrustedDeviceController extends Controller
{
    /**
     * Revoke every trusted device; each will be asked for a code at the next sign-in.
     */
    public function destroy(Request $request, TrustedDeviceManager $trustedDevices): RedirectResponse
    {
        $trustedDevices->revokeAll($request->user());

        return back()->with('status', __('Trusted devices removed. A code will be required at the next sign-in.'));
    }
}
