<?php

namespace App\Services\Translation;

use App\Ai\Agents\SiteTranslationAgent;
use App\Models\Translation;
use App\Models\TranslationString;
use App\Services\Ai\AiModels;
use App\Services\Ai\RetriesAiConnections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fills in missing translations with the AI model chosen in Admin → Languages. Only confirmed texts are sent
 * (see StringCollector), never more than the daily limit, and hand-written translations are never replaced.
 * Runs every few minutes from the scheduler and from the "Translate now" button.
 */
class MachineTranslator
{
    use RetriesAiConnections;

    public const BATCH_SIZE = 25;

    public function __construct(
        private TranslationSettings $settings,
        private AiModels $models,
    ) {}

    /**
     * Whether automatic translation can run: translation is on and its AI model is switched on.
     */
    public function isAvailable(): bool
    {
        $provider = $this->settings->provider();

        return $this->settings->enabled() && $provider !== null && $this->models->provider($provider) !== null;
    }

    /**
     * How many more texts can be sent today.
     */
    public function remainingToday(): int
    {
        return max(0, $this->settings->dailyLimit() - (int) Cache::get($this->usageKey(), 0));
    }

    /**
     * Translate missing texts, up to `$max` and the daily limit.
     *
     * @return int The number of translations saved.
     */
    public function translatePending(?int $max = null): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        $provider = $this->models->provider((string) $this->settings->provider());
        $budget = min($this->remainingToday(), $max ?? PHP_INT_MAX);
        $saved = 0;

        foreach ($this->settings->languages() as $locale) {
            while ($budget > 0) {
                $strings = TranslationString::query()
                    ->whereNotNull('confirmed_at')
                    ->whereDoesntHave('translations', fn ($query) => $query->where('locale', $locale))
                    ->orderBy('id')
                    ->limit(min(self::BATCH_SIZE, $budget))
                    ->get(['id', 'text']);

                if ($strings->isEmpty()) {
                    break;
                }

                $budget -= $strings->count();
                // Counted before sending, so a failed request still counts against the limit.
                Cache::add($this->usageKey(), 0, now()->endOfDay());
                Cache::increment($this->usageKey(), $strings->count());

                try {
                    $response = $this->withConnectionRetries(fn () => (new SiteTranslationAgent(Languages::name($locale)))->prompt(
                        json_encode($strings->map(fn (TranslationString $string) => ['id' => $string->id, 'text' => $string->text])->all(), JSON_UNESCAPED_UNICODE),
                        provider: [$provider],
                    ));
                } catch (Throwable $exception) {
                    // Provider errors can include request details, so only the provider name is logged with them.
                    Log::warning('Site translation failed.', ['provider' => $this->settings->provider(), 'locale' => $locale, 'exception' => $exception]);

                    break 2;
                }

                $saved += $this->store($locale, $strings->pluck('text', 'id')->all(), $response['translations'] ?? []);
            }
        }

        if ($saved > 0) {
            $this->settings->bumpVersion();
        }

        return $saved;
    }

    /**
     * Save the answers that match a text that was sent. Answers for other ids, empty answers and answers far
     * longer than the original are ignored.
     *
     * @param  array<int, string>  $sent  id => original text
     * @param  mixed  $answers
     */
    private function store(string $locale, array $sent, $answers): int
    {
        $saved = 0;

        foreach (is_array($answers) ? $answers : [] as $answer) {
            $id = is_array($answer) ? ($answer['id'] ?? null) : null;
            $text = is_array($answer) && is_string($answer['text'] ?? null) ? trim($answer['text']) : '';

            if (! is_int($id) || ! isset($sent[$id]) || $text === '' || mb_strlen($text) > max(40, mb_strlen($sent[$id]) * 4)) {
                continue;
            }

            $translation = Translation::query()->firstOrNew(['translation_string_id' => $id, 'locale' => $locale]);

            if ($translation->exists && $translation->is_manual) {
                continue;
            }

            $translation->forceFill(['translation_string_id' => $id, 'text' => $text, 'is_manual' => false])->save();
            $saved++;
        }

        return $saved;
    }

    private function usageKey(): string
    {
        return 'translation:machine-usage:'.now()->toDateString();
    }
}
