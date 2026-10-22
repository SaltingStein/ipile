<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like Laravel's `guest` middleware, but lets signed-in guest accounts through so they can register
 * (upgrading in place) or sign in to an existing account.
 */
class RedirectIfRegistered
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_guest) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
