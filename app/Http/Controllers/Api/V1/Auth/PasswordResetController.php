<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Password reset for API clients. The emailed link opens the web reset page; apps may also submit the token here.
 */
class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password — always answers the same way so registered emails are not revealed.
     */
    public function sendLink(Request $request, PasswordResetter $resetter): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $resetter->sendResetLink($request->string('email')->toString());

        return response()->json([
            'message' => __('If an account exists for that email, we have sent a password reset link.'),
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function reset(Request $request, PasswordResetter $resetter): JsonResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = $resetter->reset($credentials);

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
