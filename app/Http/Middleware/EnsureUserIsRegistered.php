<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send guest accounts to registration before they use account-only features such as security settings.
 */
class EnsureUserIsRegistered
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_guest) {
            // `guest()` remembers the page so registration can return there (e.g. a team invitation link).
            return redirect()->guest(route('register'))
                ->with('status', __('Create an account to access this page. Your guest activity will be kept.'));
        }

        return $next($request);
    }
}
