<?php

namespace App\Services\Translation;

use App\Models\Translation;
use Illuminate\Support\Facades\Cache;

/**
 * Every translation for one language, as original text => translation, for the browser to apply to public pages.
 * Cached per settings version, so a changed translation reaches visitors straight away.
 */
class Dictionary
{
    public function __construct(private TranslationSettings $settings) {}

    /**
     * @return array<string, string>
     */
    public function for(string $locale): array
    {
        return Cache::rememberForever("translation:dictionary:{$locale}:{$this->settings->version()}", fn () => Translation::query()
            ->join('translation_strings', 'translation_strings.id', '=', 'translations.translation_string_id')
            ->where('translations.locale', $locale)
            // Hand-written translations always count; machine ones only for confirmed texts.
            ->where(fn ($query) => $query->where('translations.is_manual', true)->orWhereNotNull('translation_strings.confirmed_at'))
            ->select(['translations.text as translation', 'translation_strings.text as source'])
            ->pluck('translation', 'source')
            ->all());
    }
}
