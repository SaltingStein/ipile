<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;

class ConsentSettings
{
    public const KEY = 'site.consent';

    public const COOKIE = 'site_consent';

    public function config(): array
    {
        return json_decode(Setting::valueOf(self::KEY, 'null'), true) ?? [
            'version' => 1, 'enabled' => true, 'expiry_days' => 180,
            'analytics_enabled' => true, 'marketing_enabled' => true,
            'accept_label' => 'Accept optional cookies', 'reject_label' => 'Reject optional cookies', 'save_label' => 'Save preferences',
            'notices' => [
                ['id' => 'cookies', 'enabled' => true, 'title' => 'Your cookie choices', 'body' => 'Necessary cookies support sign-in and security. Choose whether to allow optional analytics and marketing cookies. You can change your choice from the footer.'],
                ['id' => 'ndpr', 'enabled' => false, 'title' => 'Nigeria privacy notice', 'body' => 'See our Privacy Policy for information about processing personal data, applicable Nigerian data protection requirements and how to contact us.'],
                ['id' => 'gdpr', 'enabled' => false, 'title' => 'GDPR privacy notice', 'body' => 'See our Privacy Policy for processing purposes, applicable rights and privacy contact details. Optional cookie preferences can be changed at any time.'],
            ],
        ];
    }

    public function update(array $config): void
    {
        $config['version'] = $this->config()['version'] + 1;
        Setting::store(self::KEY, json_encode($config, JSON_THROW_ON_ERROR));
    }

    public function choice(Request $request): ?array
    {
        $choice = json_decode($request->cookie(self::COOKIE, 'null'), true);
        $config = $this->config();

        return is_array($choice)
            && ($choice['version'] ?? null) === $config['version']
            && ($choice['expires_at'] ?? 0) > now()->timestamp
            ? $choice : null;
    }
}
