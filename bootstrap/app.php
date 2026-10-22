<?php

use App\Http\Middleware\EnforceSecurityStepUp;
use App\Http\Middleware\EnsureFreshTwoFactor;
use App\Http\Middleware\EnsureTwoFactorIsConfigured;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsRegistered;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfRegistered;
use App\Http\Middleware\TrackSecurityEvent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/app.php'],
        api: [__DIR__.'/../routes/api.php', __DIR__.'/../routes/app-api.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'registered' => EnsureUserIsRegistered::class,
            'redirect.registered' => RedirectIfRegistered::class,
            'two-factor.configured' => EnsureTwoFactorIsConfigured::class,
            'security.track' => TrackSecurityEvent::class,
            'security.confirm' => EnsureFreshTwoFactor::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureUserIsActive::class,
            EnforceSecurityStepUp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create();
