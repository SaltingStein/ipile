<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\BrandingSettings;
use App\Services\Notifications\Notify;
use App\Services\Security\Security;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time password (TOTP) support compatible with Google Authenticator, Authy and similar apps.
 */
class TwoFactorAuthenticator
{
    /**
     * Number of 30-second periods either side of "now" that are still accepted, to tolerate clock drift.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private Google2FA $engine) {}

    /**
     * Start setup by storing a new, unconfirmed secret. Any previous secret and recovery codes are discarded.
     */
    public function beginSetup(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->engine->generateSecretKey(32),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_window' => null,
        ])->save();
    }

    /**
     * Confirm setup with a code from the authenticator app and issue recovery codes.
     */
    public function confirmSetup(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null || ! $this->verify($user, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
        ])->save();

        Security::recordFor($user, 'account.two_factor_enabled');
        Notify::event('security.two_factor_enabled')->to($user)->with(['link' => route('security.show')])->send();

        return true;
    }

    /**
     * Remove the authenticator app and recovery codes from the account.
     */
    public function disable(User $user): void
    {
        $wasEnabled = $user->hasEnabledTwoFactorAuthentication();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_window' => null,
        ])->save();

        if ($wasEnabled) {
            Security::recordFor($user, 'account.two_factor_disabled');
            Notify::event('security.two_factor_disabled')->to($user)->with(['link' => route('security.show')])->send();
        }
    }

    /**
     * Check a six-digit code. A code that was already accepted cannot be used again (replay protection).
     */
    public function verify(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $acceptedWindow = $this->engine->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_used_window ?? 0,
            self::WINDOW,
        );

        if ($acceptedWindow === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_window' => $acceptedWindow])->save();

        return true;
    }

    /**
     * Accept a recovery code once, removing it from the account.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $storedCode) {
            if (hash_equals($storedCode, trim($code))) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Replace the user's recovery codes with a fresh set.
     */
    public function regenerateRecoveryCodes(User $user): void
    {
        $user->forceFill(['two_factor_recovery_codes' => $this->generateRecoveryCodes()])->save();
    }

    /**
     * Build the otpauth:// URL that authenticator apps read from the QR code.
     */
    public function provisioningUrl(User $user): string
    {
        return $this->engine->getQRCodeUrl(app(BrandingSettings::class)->appName(), $user->email ?? $user->display_name, $user->two_factor_secret);
    }

    /**
     * Render the provisioning URL as an inline SVG QR code.
     */
    public function qrCodeSvg(User $user): string
    {
        $style = new RendererStyle(192, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(15, 23, 42)));

        $svg = (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))->writeString($this->provisioningUrl($user));

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
