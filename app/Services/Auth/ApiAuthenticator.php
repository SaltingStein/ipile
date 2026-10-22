<?php

namespace App\Services\Auth;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues Sanctum tokens after the user's identity is proven, or a short-lived challenge token when the
 * account has two-factor authentication enabled.
 */
class ApiAuthenticator
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(private ApiTokens $tokens) {}

    /**
     * Respond to a successful first factor (password or OAuth).
     *
     * @throws ValidationException
     */
    public function login(User $user, string $deviceName): JsonResponse
    {
        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['email' => __('This account has been suspended.')]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $challengeToken = Str::random(64);

            Cache::put($this->challengeKey($challengeToken), [
                'user_id' => $user->getKey(),
                'device_name' => $deviceName,
            ], self::CHALLENGE_TTL_SECONDS);

            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challengeToken,
                'expires_in' => self::CHALLENGE_TTL_SECONDS,
            ]);
        }

        return $this->issueToken($user, $deviceName);
    }

    /**
     * Create a token for the device and return it with the user profile.
     */
    public function issueToken(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $this->tokens->create($user, $deviceName)->plainTextToken,
            'user' => new UserResource($user),
        ], $status);
    }

    /**
     * Look up a pending two-factor challenge.
     *
     * @return array{user_id: int, device_name: string}|null
     */
    public function pendingChallenge(string $challengeToken): ?array
    {
        return Cache::get($this->challengeKey($challengeToken));
    }

    /**
     * Invalidate a challenge once it has been used.
     */
    public function forgetChallenge(string $challengeToken): void
    {
        Cache::forget($this->challengeKey($challengeToken));
    }

    private function challengeKey(string $challengeToken): string
    {
        return 'api-two-factor-challenge:'.hash('sha256', $challengeToken);
    }
}
