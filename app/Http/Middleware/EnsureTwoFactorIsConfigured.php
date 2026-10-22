<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthenticationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require an authenticator app for members of the roles selected in the admin dashboard.
 */
class EnsureTwoFactorIsConfigured
{
    public function __construct(private AuthenticationSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $requiredRoles = $this->settings->twoFactorRequiredRoles();

        if ($user === null || $requiredRoles === [] || $user->hasEnabledTwoFactorAuthentication() || ! $user->hasAnyRole($requiredRoles)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Two-factor authentication must be enabled for this account.');
        }

        return redirect()->route('security.show')
            ->with('status', __('Your role requires two-factor authentication. Set up an authenticator app to continue.'));
    }
}
