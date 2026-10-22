<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;

/**
 * Many security events in the last minute from the same account (or the same IP address when nobody is
 * signed in), which is typical of scripts rather than people.
 */
class BurstSignal implements SecuritySignal
{
    private const WINDOW_SECONDS = 60;

    /** Events allowed in the window before the signal starts to rise. */
    private const ALLOWED = 5;

    /** Extra events (beyond the allowance) at which the signal is at full strength. */
    private const FULL_AT = 15;

    public function key(): string
    {
        return 'burst';
    }

    public function label(): string
    {
        return 'Burst of activity';
    }

    public function description(): string
    {
        return 'More than '.self::ALLOWED.' security events within a minute from the same account or IP address.';
    }

    public function weight(): float
    {
        return 0.3;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        $query = SecurityEvent::query()->where('occurred_at', '>=', $context->occurredAt->copy()->subSeconds(self::WINDOW_SECONDS));

        if ($context->user !== null) {
            $query->where('user_id', $context->user->getKey());
        } elseif ($context->ipAddress !== null) {
            $query->where('ip_address', $context->ipAddress);
        } else {
            return SignalResult::none();
        }

        $recent = $query->count() + 1;

        if ($recent <= self::ALLOWED) {
            return SignalResult::none();
        }

        return new SignalResult(($recent - self::ALLOWED) / self::FULL_AT, "{$recent} security events in the last minute.");
    }
}
