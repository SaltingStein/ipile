<?php

namespace Tests\Feature\Api;

use App\Services\RateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guard: a new /api/v1 route without a baseline limiter fails the build.
     */
    public function test_every_api_v1_route_has_the_baseline_for_its_audience(): void
    {
        $unlimited = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => Str::startsWith($route->uri(), 'api/v1/'))
            ->reject(function (Route $route) {
                $middleware = $route->gatherMiddleware();
                $baseline = in_array('auth:sanctum', $middleware, true) ? RateLimits::API : RateLimits::API_PUBLIC;

                return in_array('throttle:'.$baseline, $middleware, true);
            })
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unlimited, 'These /api/v1 routes are missing their baseline rate limit.');
    }

    public function test_every_throttled_route_uses_a_defined_named_limiter(): void
    {
        $unknown = collect(app('router')->getRoutes()->getRoutes())
            ->flatMap(fn (Route $route) => collect($route->gatherMiddleware())
                ->filter(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'throttle:'))
                ->map(fn (string $middleware) => Str::after($middleware, 'throttle:'))
                ->reject(fn (string $name) => isset(RateLimits::LIMITS[$name]) || RateLimiter::limiter($name) !== null)
                ->map(fn (string $name) => $route->uri().' uses throttle:'.$name))
            ->values()
            ->all();

        $this->assertSame([], $unknown, 'Use a named limiter from App\Services\RateLimits, not throttle:N,M.');
    }

    public function test_route_limit_returns_429_once_used_up(): void
    {
        for ($attempt = 0; $attempt < RateLimits::LIMITS['register']['per_minute']; $attempt++) {
            $this->postJson(route('api.v1.auth.register'))->assertUnprocessable();
        }

        $this->postJson(route('api.v1.auth.register'))->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_one_endpoints_traffic_does_not_use_up_another_endpoints_limit(): void
    {
        for ($attempt = 0; $attempt <= RateLimits::LIMITS['register']['per_minute']; $attempt++) {
            $this->postJson(route('api.v1.auth.register'));
        }

        $this->postJson(route('api.v1.auth.forgot-password'))->assertUnprocessable();
    }
}
