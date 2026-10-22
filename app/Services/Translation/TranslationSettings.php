<?php

namespace App\Services\Translation;

use App\Models\Setting;

/**
 * Site translation, set in Admin → Languages: whether visitors can switch the public pages to other languages,
 * which languages, which AI model writes the translations (none: admins write them), and a daily limit on how
 * many texts the AI model translates. Off by default.
 */
class TranslationSettings
{
    public const ENABLED = 'translation.enabled';

    public const LANGUAGES = 'translation.languages';

    public const PROVIDER = 'translation.provider';

    public const DAILY_LIMIT = 'translation.daily_limit';

    /**
     * Bumped whenever a translation changes, so browsers fetch the new list.
     */
    public const VERSION = 'translation.version';

    public const DEFAULT_DAILY_LIMIT = 500;

    public const MAX_DAILY_LIMIT = 20000;

    public function enabled(): bool
    {
        return Setting::valueOf(self::ENABLED, '0') === '1' && $this->languages() !== [];
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        $codes = json_decode((string) Setting::valueOf(self::LANGUAGES, '[]'), true);

        return array_values(array_filter(is_array($codes) ? $codes : [], fn (mixed $code) => is_string($code) && Languages::exists($code)));
    }

    public function offers(string $code): bool
    {
        return $this->enabled() && in_array($code, $this->languages(), true);
    }

    /**
     * The AI model provider key (Admin → Integrations → AI models), or null for hand-written translations only.
     */
    public function provider(): ?string
    {
        return Setting::valueOf(self::PROVIDER, '') ?: null;
    }

    public function dailyLimit(): int
    {
        $limit = (int) Setting::valueOf(self::DAILY_LIMIT, (string) self::DEFAULT_DAILY_LIMIT);

        return max(0, min($limit, self::MAX_DAILY_LIMIT));
    }

    public function version(): string
    {
        return Setting::valueOf(self::VERSION, '') ?: '0';
    }

    public function bumpVersion(): void
    {
        Setting::store(self::VERSION, (string) now()->getTimestampMs());
    }

    /**
     * @return array{enabled: bool, languages: list<string>, provider: string|null, daily_limit: int}
     */
    public function all(): array
    {
        return [
            'enabled' => Setting::valueOf(self::ENABLED, '0') === '1',
            'languages' => $this->languages(),
            'provider' => $this->provider(),
            'daily_limit' => $this->dailyLimit(),
        ];
    }

    /**
     * @param  list<string>  $languages
     */
    public function update(bool $enabled, array $languages, ?string $provider, int $dailyLimit): void
    {
        Setting::store(self::ENABLED, $enabled ? '1' : '0');
        Setting::store(self::LANGUAGES, json_encode(array_values(array_unique($languages))));
        Setting::store(self::PROVIDER, $provider ?? '');
        Setting::store(self::DAILY_LIMIT, (string) $dailyLimit);
        $this->bumpVersion();
    }

    /**
     * What the public pages need: the languages on offer and the address of their translations.
     *
     * @return array{source: string, languages: list<array{code: string, name: string, native: string, dir: string}>, version: string}|null
     */
    public function forVisitors(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return [
            'source' => Languages::SOURCE,
            'languages' => Languages::options($this->languages()),
            'version' => $this->version(),
        ];
    }
}
