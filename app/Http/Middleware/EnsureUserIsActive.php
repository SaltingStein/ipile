<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent suspended accounts from continuing through active sessions or API tokens.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            // End the browser session; API tokens are left in place so access returns if the suspension is lifted.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403, 'Your account has been suspended by system administrators.');
        }

        return $next($request);
    }
}
