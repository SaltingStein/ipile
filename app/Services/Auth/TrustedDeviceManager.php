<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Lets a user skip the two-factor challenge on a browser they chose to trust.
 *
 * The browser keeps a random token in an encrypted cookie; only its SHA-256 hash is stored.
 */
class TrustedDeviceManager
{
    public const COOKIE = 'trusted_device';

    public function __construct(private AuthenticationSettings $settings) {}

    /**
     * Determine whether this browser holds a valid trust token for the user.
     */
    public function isTrusted(User $user, Request $request): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '' || $this->settings->trustedDeviceDays() === 0) {
            return false;
        }

        $device = $user->trustedDevices()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        $device?->forceFill(['last_used_at' => now()])->save();

        return $device !== null;
    }

    /**
     * Trust this browser for the dashboard-configured number of days.
     */
    public function trust(User $user, Request $request): void
    {
        $days = $this->settings->trustedDeviceDays();

        if ($days === 0) {
            return;
        }

        $token = Str::random(64);

        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        Cookie::queue(self::COOKIE, $token, $days * 24 * 60);
    }

    /**
     * Forget every trusted browser, e.g. after a password reset or a two-factor change.
     */
    public function revokeAll(User $user): void
    {
        $user->trustedDevices()->delete();
    }
}
