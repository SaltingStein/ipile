<?php

namespace App\Services\Auth;

use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Socialite;

/**
 * Exchanges an access token obtained by a native mobile SDK (e.g. Google Sign-In) for the provider's user profile.
 */
class OAuthTokenResolver
{
    /**
     * Fetch the provider profile for the token. The provider must already be configured.
     */
    public function resolve(string $provider, string $accessToken): SocialiteUser
    {
        return Socialite::driver($provider)->stateless()->userFromToken($accessToken);
    }
}
