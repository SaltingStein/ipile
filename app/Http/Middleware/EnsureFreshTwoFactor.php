<?php

namespace App\Http\Middleware;

use App\Services\Security\FreshTwoFactor;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `security.confirm:2fa,{minutes},{fallback}`: the route opens only if this browser entered a code from the
 * authenticator app within the last {minutes} (default 10). Others are sent to confirm and brought back; JSON
 * callers get `423` with `confirm_url`. Accounts without two-factor set one up first (`setup`, the default) or
 * confirm their password (`password`).
 *
 *     Route::post('/payouts', ...)->middleware('security.confirm:2fa,5');
 *     Route::put('/bank-details', ...)->middleware('security.confirm:2fa,10,password');
 */
class EnsureFreshTwoFactor
{
    public function __construct(private FreshTwoFactor $freshTwoFactor) {}

    public function handle(Request $request, Closure $next, string $method = '2fa', string $minutes = '10', string $fallback = FreshTwoFactor::FALLBACK_SETUP): Response
    {
        if ($method !== '2fa') {
            throw new InvalidArgumentException("security.confirm supports [2fa]; [{$method}] is not a confirmation method.");
        }

        $this->freshTwoFactor->ensure($request->user(), (int) $minutes, $fallback, $request);

        return $next($request);
    }
}
