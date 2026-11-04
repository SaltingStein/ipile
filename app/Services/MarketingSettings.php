<?php

namespace App\Services;

use App\Models\Setting;

class MarketingSettings
{
    public const ENABLED = 'marketing.enabled';

    public const REPOSITORY = 'marketing.repository_url';

    public function enabled(): bool
    {
        return Setting::valueOf(self::ENABLED, '0') === '1';
    }

    public function repositoryUrl(): string
    {
        return Setting::valueOf(self::REPOSITORY, 'https://github.com/SaltingStein/ipile');
    }

    public function update(bool $enabled, string $repositoryUrl): void
    {
        Setting::store(self::ENABLED, $enabled ? '1' : '0');
        Setting::store(self::REPOSITORY, $repositoryUrl);
    }
}
