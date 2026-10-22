<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Services\Auth\ApiAuthenticator;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/auth/login
 */
class LoginController extends Controller
{
    /**
     * Exchange email/password for a token, or a two-factor challenge token.
     */
    public function __invoke(LoginRequest $request, ApiAuthenticator $authenticator): JsonResponse
    {
        $user = $request->validateCredentials();

        return $authenticator->login($user, $request->string('device_name')->toString());
    }
}
