<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The site theme chosen in Admin → Theme. A theme sets the colours of every page (through the
 * `data-theme` attribute and the overrides in resources/css/app.css) and the layout of the landing page.
 */
class ThemeSettings
{
    public const KEY = 'site.theme';

    public const DEFAULT = 'fintech';

    /**
     * Before themes existed only the landing layout was stored; its value still picks the matching theme.
     */
    public const LEGACY_LANDING_LAYOUT = 'active_landing_layout';

    /**
     * Theme key => details. `landing` is a page in resources/js/pages/Landing; `swatches` are the background,
     * surface, text and accent colours shown in the dashboard preview.
     *
     * @var array<string, array{label: string, description: string, landing: string, swatches: list<string>}>
     */
    public const THEMES = [
        'fintech' => [
            'label' => 'Fintech Clean',
            'description' => 'Dark slate with emerald accents. Landing page: headline card.',
            'landing' => 'LayoutAlpha',
            'swatches' => ['#020617', '#0f172b', '#f1f5f9', '#00bc7d'],
        ],
        'bold' => [
            'label' => 'Bold Dark',
            'description' => 'Deep navy with bright cyan accents. Landing page: centred, high-impact hero.',
            'landing' => 'LayoutBeta',
            'swatches' => ['#050816', '#0b1430', '#e6f4ff', '#00b8db'],
        ],
        'minimal' => [
            'label' => 'Minimal Light',
            'description' => 'Light stone background with dark text and deep green accents. Landing page: text-first.',
            'landing' => 'LayoutGamma',
            'swatches' => ['#fafaf9', '#ffffff', '#1c1917', '#007a55'],
        ],
    ];

    public function current(): string
    {
        $theme = Setting::valueOf(self::KEY)
            ?? $this->fromLegacyLandingLayout(Setting::valueOf(self::LEGACY_LANDING_LAYOUT));

        return array_key_exists((string) $theme, self::THEMES) ? $theme : self::DEFAULT;
    }

    /**
     * The landing page component for the current theme, e.g. `LayoutAlpha`.
     */
    public function landingLayout(): string
    {
        return self::THEMES[$this->current()]['landing'];
    }

    /**
     * @return list<array{value: string, label: string, description: string, landing: string, swatches: list<string>}>
     */
    public function options(): array
    {
        return array_map(
            fn (string $value, array $theme): array => ['value' => $value, ...$theme],
            array_keys(self::THEMES),
            self::THEMES,
        );
    }

    public function update(string $theme): void
    {
        Setting::store(self::KEY, $theme);
    }

    private function fromLegacyLandingLayout(?string $layout): ?string
    {
        foreach (self::THEMES as $key => $theme) {
            if ($theme['landing'] === $layout) {
                return $key;
            }
        }

        return null;
    }
}
