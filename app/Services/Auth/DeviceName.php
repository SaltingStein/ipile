<?php

namespace App\Services\Auth;

/**
 * Turns a user agent string into a short, readable device name such as "Chrome on Windows".
 * The result only ever uses the fixed words below, so it is safe to show or send anywhere.
 */
class DeviceName
{
    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform === null ? $browser : "{$browser} on {$platform}";
    }
}
