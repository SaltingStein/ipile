<?php

namespace App\Http\Middleware;

use App\Services\Setup\SuperAdminSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the site has a super admin, every page opens the first-run setup screen, so a fresh deploy cannot be
 * used (or registered on) before its owner claims it with the setup key.
 */
class RedirectToSetup
{
    /**
     * Routes that must keep working before setup: the setup page itself, the health check, and what the
     * setup page loads (legal links, the cookie banner, translations).
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = ['setup.*', 'legal.show', 'consent.store', 'translations.*'];

    public function __construct(private SuperAdminSetup $setup) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::ALLOWED_ROUTES) || $request->is('up') || $this->setup->isComplete()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('This site is not set up yet.'),
                'setup_url' => route('setup.create'),
            ], 503);
        }

        return redirect()->route('setup.create');
    }
}
