<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\BrowserSessionRegistry;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Security\SecurityPresenter;
use App\Services\Security\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account security page: password, authenticator app, connected accounts, sessions, trusted devices and recent
 * security activity.
 */
class SecurityController extends Controller
{
    /**
     * Show the security page. It sits behind `password.confirm` because it reveals the setup key and recovery codes.
     */
    public function show(
        Request $request,
        TwoFactorAuthenticator $twoFactor,
        BrowserSessionRegistry $sessions,
        OAuthProviderRegistry $providers,
        AuthenticationSettings $settings,
        SecurityPresenter $presenter,
        SecuritySettings $securitySettings,
    ): Response {
        $user = $request->user();
        $isTwoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();
        $isTwoFactorPending = $user->two_factor_secret !== null && ! $isTwoFactorEnabled;
        $requiredRoles = $settings->twoFactorRequiredRoles();

        $enabledProviders = $providers->enabled()->pluck('provider')->all();
        $linkedAccounts = $user->socialAccounts()->get()->keyBy('provider');

        return Inertia::render('Settings/Security', [
            'hasPassword' => $user->hasPassword(),
            'twoFactor' => [
                'enabled' => $isTwoFactorEnabled,
                'pending' => $isTwoFactorPending,
                'required' => $requiredRoles !== [] && $user->hasAnyRole($requiredRoles),
                'qrCodeSvg' => $isTwoFactorPending ? $twoFactor->qrCodeSvg($user) : null,
                'setupKey' => $isTwoFactorPending ? $user->two_factor_secret : null,
                'recoveryCodes' => $isTwoFactorEnabled ? ($user->two_factor_recovery_codes ?? []) : [],
            ],
            'connectedAccounts' => collect(OAuthProviderRegistry::SUPPORTED)
                ->filter(fn (string $label, string $provider) => in_array($provider, $enabledProviders, true) || $linkedAccounts->has($provider))
                ->map(fn (string $label, string $provider) => [
                    'provider' => $provider,
                    'label' => $label,
                    'connected' => $linkedAccounts->has($provider),
                    'email' => $linkedAccounts->get($provider)?->email,
                    'canConnect' => in_array($provider, $enabledProviders, true),
                ])
                ->values(),
            'sessions' => [
                'supported' => $sessions->isSupported(),
                'items' => $sessions->forUser($user, $request->session()->getId()),
            ],
            // Aegis activity for this account; the owner sees what was recorded about them.
            'activity' => [
                'enabled' => $securitySettings->mode()->isRecording(),
                'flagAt' => $securitySettings->incidentThreshold(),
                'items' => $user->securityEvents()
                    ->latest('occurred_at')
                    ->latest('id')
                    ->limit(15)
                    ->get()
                    ->map(fn (SecurityEvent $event) => Arr::only($presenter->event($event), ['id', 'label', 'ip_address', 'device', 'risk_score', 'posture_score', 'occurred_at']))
                    ->all(),
            ],
            'trustedDevices' => [
                'count' => $user->trustedDevices()->where('expires_at', '>', now())->count(),
                'days' => $settings->trustedDeviceDays(),
            ],
        ]);
    }
}
