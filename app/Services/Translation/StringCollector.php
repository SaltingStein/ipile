<?php

namespace App\Services\Translation;

use App\Models\TranslationString;

/**
 * Records text the browser found on public pages that has no translation yet. Anyone can send text, so:
 *
 * - each text is cleaned and limited ({@see MAX_LENGTH}, {@see MAX_PER_REQUEST}, at most {@see MAX_STRINGS} in all);
 * - a text is only *confirmed* (and so machine-translated) once a second visitor, from another IP address,
 *   reports it too, or when a campaign or translation manager reports it. Real page text is confirmed within a
 *   few visits; text one person made up never is, and is deleted after {@see UNCONFIRMED_DAYS} days.
 *
 * IP addresses are never stored: only a keyed hash, to tell one reporter from another.
 */
class StringCollector
{
    public const MAX_LENGTH = 500;

    public const MAX_PER_REQUEST = 50;

    public const MAX_STRINGS = 20000;

    public const UNCONFIRMED_DAYS = 7;

    /**
     * @param  array<mixed>  $texts
     * @return int How many texts were new.
     */
    public function record(array $texts, string $ip, bool $trusted = false): int
    {
        $byHash = [];

        foreach (array_slice($texts, 0, self::MAX_PER_REQUEST) as $text) {
            $clean = self::clean($text);

            if ($clean !== null) {
                $byHash[TranslationString::hashOf($clean)] = $clean;
            }
        }

        if ($byHash === []) {
            return 0;
        }

        $reporter = hash_hmac('sha256', $ip, (string) config('app.key'));
        $existing = TranslationString::query()->whereIn('hash', array_keys($byHash))->get()->keyBy('hash');
        $room = max(0, self::MAX_STRINGS - TranslationString::query()->count());
        $created = 0;

        foreach ($byHash as $hash => $text) {
            $string = $existing->get($hash);

            if ($string === null) {
                if ($created >= $room) {
                    continue;
                }

                TranslationString::query()->insertOrIgnore([
                    'hash' => $hash,
                    'text' => $text,
                    'reporter_hash' => $reporter,
                    'confirmed_at' => $trusted ? now() : null,
                    'last_seen_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;

                continue;
            }

            $updates = [];

            if ($string->confirmed_at === null && ($trusted || ! hash_equals($string->reporter_hash, $reporter))) {
                $updates['confirmed_at'] = now();
            }

            // Kept roughly up to date (once a day) so unused text can be found without a write on every visit.
            if ($string->last_seen_at === null || $string->last_seen_at->lt(now()->subDay())) {
                $updates['last_seen_at'] = now();
            }

            if ($updates !== []) {
                $string->forceFill($updates)->save();
            }
        }

        return $created;
    }

    /**
     * Delete texts nobody else reported within {@see UNCONFIRMED_DAYS} days.
     */
    public function pruneUnconfirmed(): int
    {
        return TranslationString::query()
            ->whereNull('confirmed_at')
            ->where('created_at', '<', now()->subDays(self::UNCONFIRMED_DAYS))
            ->delete();
    }

    /**
     * Whitespace collapsed; null for text with no letters (numbers, symbols) or that is too long.
     */
    public static function clean(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($clean === '' || mb_strlen($clean) > self::MAX_LENGTH || preg_match('/\p{L}/u', $clean) !== 1) {
            return null;
        }

        return $clean;
    }
}
