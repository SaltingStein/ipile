<?php

namespace App\Services\Auth;

use App\Models\OAuthProvider;
use Illuminate\Support\Collection;

/**
 * Resolves which social login providers are usable, using credentials stored from the admin dashboard.
 */
class OAuthProviderRegistry
{
    /**
     * Providers this build ships a Socialite driver for, keyed by driver name.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'google' => 'Google',
        'github' => 'GitHub',
        'linkedin-openid' => 'LinkedIn',
        'x' => 'X',
    ];

    /**
     * Providers that are enabled and fully configured, in dashboard order.
     *
     * @return Collection<int, array{provider: string, label: string}>
     */
    public function enabled(): Collection
    {
        return OAuthProvider::query()
            ->where('is_enabled', true)
            ->whereIn('provider', array_keys(self::SUPPORTED))
            ->orderBy('sort_order')
            ->orderBy('provider')
            ->get()
            ->filter(fn (OAuthProvider $provider) => $provider->isConfigured())
            ->map(fn (OAuthProvider $provider) => [
                'provider' => $provider->provider,
                'label' => self::SUPPORTED[$provider->provider],
            ])
            ->values();
    }

    /**
     * Point Socialite at the dashboard credentials for the provider. Returns false when it is not usable.
     */
    public function configure(string $provider, bool $allowDisabled = false): bool
    {
        if (! array_key_exists($provider, self::SUPPORTED)) {
            return false;
        }

        $settings = OAuthProvider::query()->where('provider', $provider)
            ->when(! $allowDisabled, fn ($query) => $query->where('is_enabled', true))->first();

        if ($settings === null || ! $settings->isConfigured()) {
            return false;
        }

        config(["services.{$provider}" => [
            'client_id' => $settings->client_id,
            'client_secret' => $settings->client_secret,
            'redirect' => route('oauth.callback', $provider),
        ]]);

        return true;
    }

    /**
     * Every supported provider with its dashboard state. Secrets are never returned, only whether one is set.
     *
     * @return list<array{provider: string, label: string, is_enabled: bool, client_id: ?string, has_client_secret: bool, callback_url: string}>
     */
    public function forDashboard(): array
    {
        $stored = OAuthProvider::query()->whereIn('provider', array_keys(self::SUPPORTED))->get()->keyBy('provider');

        return collect(self::SUPPORTED)
            ->map(fn (string $label, string $provider) => [
                'provider' => $provider,
                'label' => $label,
                'is_enabled' => (bool) $stored->get($provider)?->is_enabled,
                'client_id' => $stored->get($provider)?->client_id,
                'has_client_secret' => filled($stored->get($provider)?->client_secret),
                'callback_url' => route('oauth.callback', $provider),
            ])
            ->values()
            ->all();
    }

    /**
     * Save dashboard changes for a provider. A blank secret keeps the stored one.
     *
     * @param  array{is_enabled: bool, client_id: ?string, client_secret: ?string}  $input
     */
    public function update(string $provider, array $input): OAuthProvider
    {
        $settings = OAuthProvider::query()->firstOrNew(['provider' => $provider]);

        $settings->fill([
            'is_enabled' => $input['is_enabled'],
            'client_id' => $input['client_id'],
        ]);

        if (filled($input['client_secret'])) {
            $settings->client_secret = $input['client_secret'];
        }

        $settings->save();

        return $settings;
    }
}
