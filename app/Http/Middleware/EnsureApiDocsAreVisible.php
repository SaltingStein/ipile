<?php

namespace App\Http\Middleware;

use App\Services\ApiDocs\ApiDocsSettings;
use App\Services\ApiDocs\ApiDocsVisibility;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Scramble docs routes with the visibility chosen in Admin → API documentation.
 * Replaces Scramble's own RestrictedDocsAccess, which opens the docs to everyone in the local environment.
 */
class EnsureApiDocsAreVisible
{
    public function __construct(private ApiDocsSettings $settings) {}

    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $visibility = $this->settings->visibility();

        abort_if($visibility === ApiDocsVisibility::Disabled, 404);

        if ($visibility === ApiDocsVisibility::Public) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || $user->is_guest) {
            throw new AuthenticationException;
        }

        abort_if($visibility === ApiDocsVisibility::Permission && $user->cannot('manage_api_docs'), 403);

        return $next($request);
    }
}
