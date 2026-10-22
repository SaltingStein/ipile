<?php

namespace App\Http\Middleware;

use App\Services\Security\Security;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a security event when the route succeeds: `->middleware('security.track:orders.refunded')`.
 * Failed requests (4xx/5xx, validation errors) are not recorded.
 */
class TrackSecurityEvent
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $response = $next($request);

        $failed = $response->getStatusCode() >= 400
            || (property_exists($response, 'exception') && $response->exception !== null);

        if (! $failed) {
            Security::record($type);
        }

        return $response;
    }
}
