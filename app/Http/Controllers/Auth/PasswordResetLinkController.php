<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password" form that emails a reset link.
 */
class PasswordResetLinkController extends Controller
{
    /**
     * Show the forgot-password form.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Send the reset link. The response is identical whether or not the email is registered.
     */
    public function store(Request $request, PasswordResetter $resetter): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $resetter->sendResetLink($request->string('email')->toString());

        return back()->with('status', __('If an account exists for that email, we have sent a password reset link.'));
    }
}
