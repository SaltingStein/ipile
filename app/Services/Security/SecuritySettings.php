<?php

namespace App\Services\Security;

use App\Models\Setting;
use App\Services\Ai\AnthropicApi;

/**
 * Dashboard-managed Aegis options (Admin → Security → Settings). Nothing here is read from .env.
 */
class SecuritySettings
{
    public const MODE = 'security.mode';

    public const THRESHOLDS = 'security.thresholds';

    public const INCIDENT_THRESHOLD = 'security.incident_threshold';

    public const DISABLED_CATEGORIES = 'security.disabled_categories';

    public const RETENTION_DAYS = 'security.retention_days';

    public const AI_ENABLED = 'security.ai_enabled';

    public const AI_THRESHOLD = 'security.ai_threshold';

    /** The AI model (Admin → Integrations → AI models) the analyst uses. Unset means Anthropic, as before CORE-910. */
    public const AI_PROVIDER = 'security.ai_provider';

    public const LAST_TRAINED_AT = 'security.last_trained_at';

    public const DEFAULT_MODE = SecurityMode::Monitor;

    public const DEFAULT_INCIDENT_THRESHOLD = 60;

    public const DEFAULT_RETENTION_DAYS = 90;

    public const DEFAULT_AI_THRESHOLD = 70;

    /**
     * Action => score (0-100) at which it happens; null means never. Suspension is off until an administrator opts in.
     *
     * @var array<string, int|null>
     */
    public const DEFAULT_THRESHOLDS = [
        'notify' => 60,
        'step_up' => 75,
        'revoke_sessions' => 90,
        'suspend' => null,
    ];

    public function mode(): SecurityMode
    {
        return SecurityMode::tryFrom((string) Setting::valueOf(self::MODE)) ?? self::DEFAULT_MODE;
    }

    /**
     * @return array<string, int|null> keyed by {@see SecurityAction} value
     */
    public function thresholds(): array
    {
        $stored = json_decode((string) Setting::valueOf(self::THRESHOLDS, '{}'), true);
        $thresholds = self::DEFAULT_THRESHOLDS;

        foreach (array_keys($thresholds) as $action) {
            if (is_array($stored) && array_key_exists($action, $stored)) {
                $thresholds[$action] = is_int($stored[$action]) ? $stored[$action] : null;
            }
        }

        return $thresholds;
    }

    /**
     * The strongest response whose threshold the score reaches.
     */
    public function actionFor(int $score): SecurityAction
    {
        $thresholds = $this->thresholds();

        foreach (SecurityAction::ladder() as $action) {
            $threshold = $thresholds[$action->value] ?? null;

            if ($threshold !== null && $score >= $threshold) {
                return $action;
            }
        }

        return SecurityAction::None;
    }

    public function incidentThreshold(): int
    {
        return (int) Setting::valueOf(self::INCIDENT_THRESHOLD, (string) self::DEFAULT_INCIDENT_THRESHOLD);
    }

    /**
     * Event categories that are out of scope: their events are ignored entirely.
     *
     * @return list<string>
     */
    public function disabledCategories(): array
    {
        $categories = json_decode((string) Setting::valueOf(self::DISABLED_CATEGORIES, '[]'), true);

        return is_array($categories) ? array_values(array_filter($categories, 'is_string')) : [];
    }

    public function isCategoryMonitored(string $category): bool
    {
        return ! in_array($category, $this->disabledCategories(), true);
    }

    public function retentionDays(): int
    {
        return (int) Setting::valueOf(self::RETENTION_DAYS, (string) self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Whether incidents are sent to the AI analyst (it also needs its AI model, {@see analysisProvider()}, to be switched on).
     */
    public function aiEnabled(): bool
    {
        return Setting::valueOf(self::AI_ENABLED, '0') === '1';
    }

    /**
     * The AI model provider key the analyst uses, or null when none is chosen.
     */
    public function analysisProvider(): ?string
    {
        return Setting::valueOf(self::AI_PROVIDER, AnthropicApi::PROVIDER) ?: null;
    }

    public function aiThreshold(): int
    {
        return (int) Setting::valueOf(self::AI_THRESHOLD, (string) self::DEFAULT_AI_THRESHOLD);
    }

    public function lastTrainedAt(): ?string
    {
        return Setting::valueOf(self::LAST_TRAINED_AT);
    }

    public function markTrained(): void
    {
        Setting::store(self::LAST_TRAINED_AT, now()->toIso8601String());
    }

    /**
     * @return array{mode: string, thresholds: array<string, int|null>, incident_threshold: int, disabled_categories: list<string>, retention_days: int, ai_enabled: bool, ai_threshold: int, ai_provider: string|null}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode()->value,
            'thresholds' => $this->thresholds(),
            'incident_threshold' => $this->incidentThreshold(),
            'disabled_categories' => $this->disabledCategories(),
            'retention_days' => $this->retentionDays(),
            'ai_enabled' => $this->aiEnabled(),
            'ai_threshold' => $this->aiThreshold(),
            'ai_provider' => $this->analysisProvider(),
        ];
    }

    /**
     * Persist the dashboard-managed options.
     *
     * @param  array{mode: string, thresholds: array<string, int|null>, incident_threshold: int, disabled_categories: list<string>, retention_days: int, ai_enabled: bool, ai_threshold: int, ai_provider?: string|null}  $settings
     */
    public function update(array $settings): void
    {
        $thresholds = [];
        foreach (array_keys(self::DEFAULT_THRESHOLDS) as $action) {
            $value = $settings['thresholds'][$action] ?? null;
            $thresholds[$action] = $value === null ? null : (int) $value;
        }

        Setting::store(self::MODE, $settings['mode']);
        Setting::store(self::THRESHOLDS, json_encode($thresholds));
        Setting::store(self::INCIDENT_THRESHOLD, (string) $settings['incident_threshold']);
        Setting::store(self::DISABLED_CATEGORIES, json_encode(array_values($settings['disabled_categories'])));
        Setting::store(self::RETENTION_DAYS, (string) $settings['retention_days']);
        Setting::store(self::AI_ENABLED, $settings['ai_enabled'] ? '1' : '0');
        Setting::store(self::AI_THRESHOLD, (string) $settings['ai_threshold']);

        if (array_key_exists('ai_provider', $settings)) {
            Setting::store(self::AI_PROVIDER, (string) $settings['ai_provider']);
        }
    }
}
