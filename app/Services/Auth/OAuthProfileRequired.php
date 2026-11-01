<?php

namespace App\Services\Auth;

use Laravel\Socialite\Contracts\User;

class OAuthProfileRequired extends SocialLoginException
{
    public function __construct(public User $identity)
    {
        parent::__construct('Complete your profile to create your account.');
    }
}
