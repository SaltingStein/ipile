<?php

namespace App\Services;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every named rate limiter, used as `throttle:{name}` on web and API routes and listed on the developer portal.
 *
 * Named limiters keep a separate counter per limiter. Laravel's numeric `throttle:N,M` shares one counter per
 * user or IP across every route that uses it, so one endpoint's traffic would use up another's allowance.
 */
final class RateLimits
{
    /**
     * Baseline for every authenticated /api/v1 route, on top of any route-specific limit.
     */
    public const API = 'api';

    /**
     * Baseline for every public /api/v1 route. Kept generous because many Nigerian mobile users share one
     * carrier IP address (CGNAT).
     */
    public const API_PUBLIC = 'api-public';

    /**
     * @var array<string, array{per_minute: int, by: 'user'|'ip'}>
     */
    public const LIMITS = [
        self::API => ['per_minute' => 120, 'by' => 'user'],
        self::API_PUBLIC => ['per_minute' => 120, 'by' => 'ip'],
        'register' => ['per_minute' => 6, 'by' => 'ip'],
        'password-reset' => ['per_minute' => 6, 'by' => 'ip'],
        'password-confirm' => ['per_minute' => 6, 'by' => 'user'],
        'verification' => ['per_minute' => 6, 'by' => 'user'],
        'two-factor' => ['per_minute' => 10, 'by' => 'ip'],
        'oauth' => ['per_minute' => 10, 'by' => 'ip'],
        'consent' => ['per_minute' => 30, 'by' => 'ip'],
        // Generous because attendees at one venue often share a Wi-Fi or carrier IP address.
        'form-submissions' => ['per_minute' => 30, 'by' => 'ip'],
        // Button clicks on campaign pages, counted in the background; a cap keeps the figures honest.
        'campaign-clicks' => ['per_minute' => 20, 'by' => 'ip'],
        // New page text reported for translation; each request carries up to 50 texts.
        'translation-reports' => ['per_minute' => 10, 'by' => 'ip'],
        // Each message costs an AI call; the daily cap in Admin → Site assistant applies on top.
        'assistant' => ['per_minute' => 10, 'by' => 'user'],
        // The third-party widget renews each visitor's claim by itself.
        'assistant-widget-claim' => ['per_minute' => 60, 'by' => 'user'],
        // The widget service's data connection (/mcp/widgets), counted per calling network.
        'mcp-widgets' => ['per_minute' => 120, 'by' => 'ip'],
        'setup' => ['per_minute' => 6, 'by' => 'ip'],
        'payments' => ['per_minute' => 10, 'by' => 'user'],
        'payment-verification' => ['per_minute' => 20, 'by' => 'user'],
        'invitations' => ['per_minute' => 20, 'by' => 'user'],
        'provider-tests' => ['per_minute' => 10, 'by' => 'user'],
        'security-analysis' => ['per_minute' => 10, 'by' => 'user'],
        'webhooks' => ['per_minute' => 120, 'by' => 'ip'],
    ];

    public static function register(): void
    {
        foreach (self::LIMITS as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit['per_minute'])->by(
                $limit['by'] === 'user' && $request->user() !== null
                    ? 'user:'.$request->user()->getAuthIdentifier()
                    : 'ip:'.$request->ip()
            ));
        }

        RateLimiter::for('magic-link', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perHour(3)->by('email-ip:'.hash('sha256', $email.'|'.$request->ip())),
                Limit::perMinute(10)->by('ip:'.$request->ip()),
            ];
        });
    }
}
