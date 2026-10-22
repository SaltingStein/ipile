<?php

namespace App\Http\Middleware;

use App\Services\Security\SecurityResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After Aegis asks for a step-up ({@see SecurityResponder}), this browser must confirm its password before any other
 * page. Confirming (Laravel's `password.confirm`) records a newer confirmation time, which clears the request.
 */
class EnforceSecurityStepUp
{
    public const SESSION_KEY = 'security.step_up_requested_at';

    public function handle(Request $request, Closure $next): Response
    {
        $requestedAt = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if ($requestedAt === null || $request->user() === null) {
            return $next($request);
        }

        if ((int) $request->session()->get('auth.password_confirmed_at', 0) >= (int) $requestedAt) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        // A fresh authenticator code also counts as a password confirmation (App\Services\Security\FreshTwoFactor).
        if ($request->routeIs('password.confirm', 'password.confirm.store', 'security.confirm', 'security.confirm.store', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Please confirm your password to continue.')], 423);
        }

        return redirect()->guest(route('password.confirm'));
    }
}
