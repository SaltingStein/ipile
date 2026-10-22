<?php

/**
 * @author Mzer Michael Terungwa
 * @license MIT
 *
 * @since 1.0
 */

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles frictionless guest onboarding for first-time visitors.
 * The controller creates a lightweight profile without forcing an email/password flow.
 */
class OnboardingController extends Controller
{
    /**
     * Create a new guest user and log them in immediately.
     */
    public function startGuestSession(Request $request, AuthenticationSettings $settings): RedirectResponse
    {
        abort_unless($settings->guestAccessEnabled(), 404, __('Guest access is disabled. Sign in to continue.'));

        $request->validate([
            'nickname' => ['required', 'string', 'min:2', 'max:20'],
        ]);

        // Store a lightweight profile so the user can enter the dashboard immediately.
        $user = User::create([
            'nickname' => trim($request->nickname),
            'is_guest' => true,
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        // Keep the guest session alive for the next request cycle with a remember cookie.
        Auth::login($user, remember: true);

        return redirect()->route('dashboard');
    }
}
