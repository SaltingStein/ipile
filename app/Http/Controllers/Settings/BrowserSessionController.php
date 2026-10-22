<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\BrowserSessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Sign out every other browser where the account is logged in.
 */
class BrowserSessionController extends Controller
{
    /**
     * Revoke other sessions and their "remember me" cookies, keeping the current browser signed in.
     */
    public function destroy(Request $request, BrowserSessionRegistry $sessions): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasPassword()) {
            $request->validate([
                'password' => ['required', 'string', 'current_password'],
            ]);

            // Rehashing the password invalidates other sessions and remember-me cookies through `auth.session`.
            Auth::guard('web')->logoutOtherDevices($request->string('password')->toString());
        } else {
            // Without a password, rotating the remember token is what invalidates remember-me cookies elsewhere.
            $user->setRememberToken(Str::random(60));
            $user->save();
        }

        $sessions->revokeOthers($user, $request->session()->getId());

        return back()->with('status', __('Other browser sessions have been signed out.'));
    }
}
