<?php

namespace App\Services\Translation;

/**
 * Languages the public site can be offered in (Admin → Languages). The site's own language is English.
 */
class Languages
{
    public const SOURCE = 'en';

    /**
     * Code => English name, own name, and text direction.
     *
     * @var array<string, array{name: string, native: string, dir: 'ltr'|'rtl'}>
     */
    public const ALL = [
        'fr' => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr'],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'dir' => 'ltr'],
        'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'dir' => 'ltr'],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'dir' => 'ltr'],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'dir' => 'ltr'],
        'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'dir' => 'ltr'],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl'],
        'zh' => ['name' => 'Chinese (Simplified)', 'native' => '简体中文', 'dir' => 'ltr'],
        'ja' => ['name' => 'Japanese', 'native' => '日本語', 'dir' => 'ltr'],
        'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'dir' => 'ltr'],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'dir' => 'ltr'],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'dir' => 'ltr'],
        'sw' => ['name' => 'Swahili', 'native' => 'Kiswahili', 'dir' => 'ltr'],
        'yo' => ['name' => 'Yoruba', 'native' => 'Yorùbá', 'dir' => 'ltr'],
        'ha' => ['name' => 'Hausa', 'native' => 'Hausa', 'dir' => 'ltr'],
        'ig' => ['name' => 'Igbo', 'native' => 'Igbo', 'dir' => 'ltr'],
        'am' => ['name' => 'Amharic', 'native' => 'አማርኛ', 'dir' => 'ltr'],
        'zu' => ['name' => 'Zulu', 'native' => 'isiZulu', 'dir' => 'ltr'],
    ];

    public static function exists(string $code): bool
    {
        return isset(self::ALL[$code]);
    }

    public static function name(string $code): string
    {
        return self::ALL[$code]['name'] ?? $code;
    }

    /**
     * @param  list<string>|null  $codes  Only these, in this order; all when null.
     * @return list<array{code: string, name: string, native: string, dir: string}>
     */
    public static function options(?array $codes = null): array
    {
        $codes ??= array_keys(self::ALL);

        return array_values(array_map(
            fn (string $code) => ['code' => $code, ...self::ALL[$code]],
            array_filter($codes, fn (string $code) => self::exists($code)),
        ));
    }
}
