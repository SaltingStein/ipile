<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationSettings;
use App\Services\RateLimits;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Developer portal (/developers): getting started with the /api/v1 API. Shares the API docs visibility setting.
 * Rate limits are read from the registered routes and App\Services\RateLimits so the page cannot drift from the code.
 */
class DeveloperPortalController extends Controller
{
    public function show(Router $router, AuthenticationSettings $settings): Response
    {
        return Inertia::render('DeveloperPortal', [
            'tokenLifetimeDays' => $settings->apiTokenLifetimeDays(),
            'tokenIdleDays' => $settings->apiTokenIdleDays(),
            'baseUrl' => url('/api/v1'),
            'docsUrl' => route('scramble.docs.ui'),
            'specUrl' => route('scramble.docs.document'),
            'webhookUrl' => url('/api/webhooks'),
            'baselineLimits' => [
                'authenticated' => RateLimits::LIMITS[RateLimits::API]['per_minute'],
                'public' => RateLimits::LIMITS[RateLimits::API_PUBLIC]['per_minute'],
            ],
            'rateLimits' => $this->rateLimits($router),
            'loginMaxAttempts' => LoginRequest::MAX_ATTEMPTS,
            'twoFactorMaxAttempts' => TwoFactorChallengeController::MAX_ATTEMPTS,
        ]);
    }

    /**
     * Every /api/v1 route with its own named limiter (`throttle:{name}`), on top of the group baseline.
     *
     * @return list<array{method: string, path: string, per_minute: int, by: string}>
     */
    private function rateLimits(Router $router): array
    {
        $limits = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! Str::startsWith($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                $name = is_string($middleware) && Str::startsWith($middleware, 'throttle:') ? Str::after($middleware, 'throttle:') : null;

                if ($name === null || in_array($name, [RateLimits::API, RateLimits::API_PUBLIC], true) || ! isset(RateLimits::LIMITS[$name])) {
                    continue;
                }

                $limits[] = [
                    'method' => $route->methods()[0],
                    'path' => Str::after($route->uri(), 'api/v1'),
                    'per_minute' => RateLimits::LIMITS[$name]['per_minute'],
                    'by' => RateLimits::LIMITS[$name]['by'],
                ];
            }
        }

        usort($limits, fn (array $a, array $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $limits;
    }
}
