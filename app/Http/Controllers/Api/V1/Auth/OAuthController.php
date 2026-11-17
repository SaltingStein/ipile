<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ApiAuthenticator;
use App\Services\Auth\OAuthProfileRequired;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\OAuthTokenResolver;
use App\Services\Auth\SocialLogin;
use App\Services\Auth\SocialLoginException;
use App\Services\Forms\RegistrationForm;
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
     * When profile completion is required for a new identity, returns 422 with requires_profile_completion
     * and signupFields. Resubmit the provider access_token with name and answers to finish registration.
     */
    public function token(
        Request $request,
        string $provider,
        OAuthProviderRegistry $providers,
        OAuthTokenResolver $resolver,
        SocialLogin $socialLogin,
        ApiAuthenticator $authenticator,
        RegistrationForm $registrationForm,
    ): JsonResponse {
        abort_unless($providers->configure($provider), 404);

        $request->validate([
            'access_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $socialUser = $resolver->resolve($provider, $request->string('access_token')->toString());
        } catch (GuzzleException|SocialLoginException) {
            throw ValidationException::withMessages(['access_token' => __('The access token could not be verified.')]);
        }

        try {
            try {
                $user = $socialLogin->resolve($provider, $socialUser, null);
            } catch (OAuthProfileRequired) {
                if (! $request->has('name')) {
                    return response()->json([
                        'message' => __('Complete your profile to create your account.'),
                        'requires_profile_completion' => true,
                        'signupFields' => $registrationForm->fields(),
                    ], 422);
                }

                $profile = $request->validate([
                    'name' => ['required', 'string', 'max:255'],
                    ...$registrationForm->rules(),
                ], [], $registrationForm->attributes());

                $user = $socialLogin->resolve($provider, $socialUser, null, $profile);
            }
        } catch (SocialLoginException $exception) {
            throw ValidationException::withMessages(['access_token' => $exception->getMessage()]);
        }

        return $authenticator->login($user, $request->string('device_name')->toString());
    }
}
