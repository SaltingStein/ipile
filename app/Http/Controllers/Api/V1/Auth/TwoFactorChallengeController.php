<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\ApiAuthenticator;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\Security;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/auth/two-factor-challenge
 */
class TwoFactorChallengeController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Exchange a challenge token plus an authenticator or recovery code for an API token.
     * The challenge is discarded after too many wrong codes.
     */
    public function __invoke(Request $request, ApiAuthenticator $authenticator, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $challengeToken = $request->string('challenge_token')->toString();
        $challenge = $authenticator->pendingChallenge($challengeToken);
        $user = $challenge === null ? null : User::query()->find($challenge['user_id']);

        if ($user === null) {
            throw ValidationException::withMessages(['challenge_token' => __('The sign-in challenge has expired. Please sign in again.')]);
        }

        $field = $request->filled('recovery_code') ? 'recovery_code' : 'code';
        $isValid = $field === 'recovery_code'
            ? $twoFactor->useRecoveryCode($user, $request->string('recovery_code')->toString())
            : $twoFactor->verify($user, $request->string('code')->toString());

        if (! $isValid) {
            $throttleKey = 'api-two-factor-challenge:'.hash('sha256', $challengeToken);
            RateLimiter::hit($throttleKey);
            Security::recordFor($user, 'auth.two_factor_failed');

            if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
                $authenticator->forgetChallenge($challengeToken);
            }

            throw ValidationException::withMessages([$field => __('The provided two-factor code was invalid.')]);
        }

        $authenticator->forgetChallenge($challengeToken);

        return $authenticator->issueToken($user, $challenge['device_name']);
    }
}
