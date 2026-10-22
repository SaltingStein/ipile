<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Services\Auth\ApiAuthenticator;
use App\Services\Auth\RegisterUser;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/auth/register
 */
class RegisterController extends Controller
{
    /**
     * Create an account and return an API token for the device.
     */
    public function __invoke(RegisterRequest $request, RegisterUser $registerUser, ApiAuthenticator $authenticator): JsonResponse
    {
        $user = $registerUser->register($request->safe()->only(['name', 'email', 'password']), null, $request->signupAnswers());

        return $authenticator->issueToken($user, $request->string('device_name')->toString(), 201);
    }
}
