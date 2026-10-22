<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ApiAuthenticator;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\OAuthTokenResolver;
use App\Services\Auth\SocialLogin;
use App\Services\Auth\SocialLoginException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Social sign-in for mobile apps that use a native SDK (e.g. Google Sign-In).
 */
class OAuthController extends Controller
{
    /**
     * GET /api/v1/auth/oauth/providers — providers enabled in the dashboard.
     */
    public function providers(OAuthProviderRegistry $providers): JsonResponse
    {
        return response()->json(['data' => $providers->enabled()]);
    }

    /**
     * POST /api/v1/auth/oauth/{provider} — exchange a provider access token for an API token.
     */
    public function token(
        Request $request,
        string $provider,
        OAuthProviderRegistry $providers,
        OAuthTokenResolver $resolver,
        SocialLogin $socialLogin,
        ApiAuthenticator $authenticator,
    ): JsonResponse {
        abort_unless($providers->configure($provider), 404);

        $request->validate([
            'access_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $socialUser = $resolver->resolve($provider, $request->string('access_token')->toString());
        } catch (GuzzleException) {
            throw ValidationException::withMessages(['access_token' => __('The access token could not be verified.')]);
        }

        try {
            $user = $socialLogin->resolve($provider, $socialUser, null);
        } catch (SocialLoginException $exception) {
            throw ValidationException::withMessages(['access_token' => $exception->getMessage()]);
        }

        return $authenticator->login($user, $request->string('device_name')->toString());
    }
}
