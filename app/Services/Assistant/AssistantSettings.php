<?php

namespace App\Services\Assistant;

use App\Models\Setting;

/**
 * The site assistant, set in Admin → Platform → Site assistant: whether signed-in people can use it, which AI
 * model answers, which business figures it may read (each still needs the asker's own permission, see
 * {@see AssistantMetrics}), how many messages each person can send a day, and what happens to someone who asks
 * for protected data ({@see lockout()}), and which staff modes are on ({@see staffModes()}). Off by default.
 */
class AssistantSettings
{
    public const ENABLED = 'assistant.enabled';

    public const SOURCE = 'assistant.source';

    public const SOURCES = ['built_in', 'third_party', 'off'];

    public const PROVIDER = 'assistant.provider';

    public const METRICS = 'assistant.metrics';

    public const INSIGHTS = 'assistant.insights';

    /** Whether the assistant answers from the Help Center (the App help capability). On by default. */
    public const HELP_ENABLED = 'assistant.help_enabled';

    /** What the business does, in the administrator's words, added to the assistant's instructions. */
    public const BUSINESS_CONTEXT = 'assistant.business_context';

    public const MAX_BUSINESS_CONTEXT = 1500;

    /** Days chats and the interaction log (Admin → Site assistant → Activity) are kept. */
    public const ACTIVITY_RETENTION_DAYS = 'assistant.activity_retention_days';

    public const DEFAULT_ACTIVITY_RETENTION_DAYS = 90;

    public const MIN_ACTIVITY_RETENTION_DAYS = 7;

    public const MAX_ACTIVITY_RETENTION_DAYS = 730;

    public const DAILY_MESSAGES = 'assistant.daily_messages';

    public const LOCKOUT = 'assistant.lockout';

    /** JSON list of the staff modes ({@see AssistantIntent::internal()}) administrators switched on. None by default. */
    public const STAFF_MODES = 'assistant.staff_modes';

    /** Suspend the account until an administrator restores it, whoever it is. The default. */
    public const LOCKOUT_SUSPEND = 'suspend';

    /** Sign out everywhere; accounts with two-factor need their authenticator code to get back in, others are suspended. */
    public const LOCKOUT_SIGN_OUT = 'sign_out';

    public const LOCKOUTS = [self::LOCKOUT_SUSPEND, self::LOCKOUT_SIGN_OUT];

    public const DEFAULT_DAILY_MESSAGES = 50;

    public const MAX_DAILY_MESSAGES = 1000;

    public function enabled(): bool
    {
        return $this->source() === 'built_in' && Setting::valueOf(self::ENABLED, '0') === '1' && $this->provider() !== null;
    }

    public function source(): string
    {
        $source = Setting::valueOf(self::SOURCE, 'built_in');

        return in_array($source, self::SOURCES, true) ? $source : 'off';
    }

    public function provider(): ?string
    {
        return Setting::valueOf(self::PROVIDER, '') ?: null;
    }

    /**
     * Keys of the business figures administrators allowed.
     *
     * @return list<string>
     */
    public function metrics(): array
    {
        $keys = json_decode((string) Setting::valueOf(self::METRICS, '[]'), true);

        return array_values(array_filter(is_array($keys) ? $keys : [], fn (mixed $key) => is_string($key) && AssistantMetrics::exists($key)));
    }

    public function dailyMessages(): int
    {
        return max(1, min((int) Setting::valueOf(self::DAILY_MESSAGES, (string) self::DEFAULT_DAILY_MESSAGES), self::MAX_DAILY_MESSAGES));
    }

    public function helpEnabled(): bool
    {
        return Setting::valueOf(self::HELP_ENABLED, '1') === '1';
    }

    public function businessContext(): ?string
    {
        return trim((string) Setting::valueOf(self::BUSINESS_CONTEXT, '')) ?: null;
    }

    public function activityRetentionDays(): int
    {
        return max(self::MIN_ACTIVITY_RETENTION_DAYS, min(self::MAX_ACTIVITY_RETENTION_DAYS, (int) Setting::valueOf(self::ACTIVITY_RETENTION_DAYS, (string) self::DEFAULT_ACTIVITY_RETENTION_DAYS)));
    }

    public function updateActivityRetention(int $days): void
    {
        Setting::store(self::ACTIVITY_RETENTION_DAYS, (string) $days);
    }

    public function updateCapabilities(bool $helpEnabled, ?string $businessContext): void
    {
        Setting::store(self::HELP_ENABLED, $helpEnabled ? '1' : '0');
        Setting::store(self::BUSINESS_CONTEXT, trim((string) $businessContext));
    }

    /** @return list<string> */
    public function insights(): array
    {
        $keys = json_decode(Setting::valueOf(self::INSIGHTS, '[]'), true);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** @param list<string> $keys */
    public function updateInsights(array $keys): void
    {
        Setting::store(self::INSIGHTS, json_encode(array_values(array_unique($keys))));
    }

    /**
     * Values of the staff modes administrators switched on.
     *
     * @return list<string>
     */
    public function staffModes(): array
    {
        $modes = json_decode((string) Setting::valueOf(self::STAFF_MODES, '[]'), true);
        $internal = array_map(fn (AssistantIntent $mode) => $mode->value, AssistantIntent::internal());

        return array_values(array_intersect($internal, is_array($modes) ? $modes : []));
    }

    public function staffModeEnabled(AssistantIntent $mode): bool
    {
        return in_array($mode->value, $this->staffModes(), true);
    }

    /**
     * What happens to someone who asks the assistant for protected data: {@see LOCKOUT_SUSPEND} or {@see LOCKOUT_SIGN_OUT}.
     */
    public function lockout(): string
    {
        $lockout = Setting::valueOf(self::LOCKOUT, self::LOCKOUT_SUSPEND);

        return in_array($lockout, self::LOCKOUTS, true) ? $lockout : self::LOCKOUT_SUSPEND;
    }

    /**
     * @return array{source: string, enabled: bool, provider: string|null, metrics: list<string>, insights: list<string>, help_enabled: bool, business_context: string, activity_retention_days: int, daily_messages: int, lockout: string, staff_modes: list<string>}
     */
    public function all(): array
    {
        return [
            'source' => $this->source(),
            'enabled' => Setting::valueOf(self::ENABLED, '0') === '1',
            'provider' => $this->provider(),
            'metrics' => $this->metrics(),
            'insights' => $this->insights(),
            'help_enabled' => $this->helpEnabled(),
            'business_context' => $this->businessContext() ?? '',
            'activity_retention_days' => $this->activityRetentionDays(),
            'daily_messages' => $this->dailyMessages(),
            'lockout' => $this->lockout(),
            'staff_modes' => $this->staffModes(),
        ];
    }

    /**
     * @param  list<string>  $metrics
     * @param  string|null  $lockout  Null keeps the current choice.
     * @param  list<string>|null  $staffModes  Values of {@see AssistantIntent::internal()}; null keeps the current choice.
     */
    public function update(bool $enabled, ?string $provider, array $metrics, int $dailyMessages, ?string $lockout = null, ?string $source = null, ?array $staffModes = null): void
    {
        if ($staffModes !== null) {
            Setting::store(self::STAFF_MODES, json_encode(array_values(array_unique($staffModes))));
        }
        if ($source !== null) {
            Setting::store(self::SOURCE, $source);
        }
        Setting::store(self::ENABLED, $enabled ? '1' : '0');
        Setting::store(self::PROVIDER, $provider ?? '');
        Setting::store(self::METRICS, json_encode(array_values(array_unique($metrics))));
        Setting::store(self::DAILY_MESSAGES, (string) $dailyMessages);

        if ($lockout !== null) {
            Setting::store(self::LOCKOUT, $lockout);
        }
    }
}
