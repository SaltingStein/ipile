<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\TrustedDeviceManager;
use App\Services\Notifications\Notify;
use App\Services\Security\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Change (or, for OAuth-only accounts, set) the account password.
 */
class PasswordController extends Controller
{
    /**
     * Update the password. Other browser sessions are signed out by `auth.session` because the hash changes.
     */
    public function update(Request $request, TrustedDeviceManager $trustedDevices): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => Rule::when($user->hasPassword(), ['required', 'string', 'current_password'], ['exclude']),
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill(['password' => $validated['password']])->save();
        $trustedDevices->revokeAll($user);
        Security::recordFor($user, 'account.password_changed');

        Notify::event('security.password_changed')->to($user)->with(['link' => route('password.request')])->send();

        return back()->with('status', __('Password saved. Other devices have been signed out.'));
    }
}
