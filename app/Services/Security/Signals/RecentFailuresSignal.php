<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;
use App\Services\Security\SecurityEventCatalog;

/**
 * Failed attempts (wrong password, bad two-factor code, lockouts) in the last 15 minutes against the account or
 * from the same IP address. Also raises the risk of a successful sign-in that follows them.
 */
class RecentFailuresSignal implements SecuritySignal
{
    private const WINDOW_MINUTES = 15;

    /** Failures at which the signal is at full strength. */
    private const FULL_AT = 8;

    public function __construct(private SecurityEventCatalog $catalog) {}

    public function key(): string
    {
        return 'recent_failures';
    }

    public function label(): string
    {
        return 'Recent failed attempts';
    }

    public function description(): string
    {
        return 'Failed sign-ins, rejected two-factor codes and lockouts in the last '.self::WINDOW_MINUTES.' minutes against the account or from the same IP address.';
    }

    public function weight(): float
    {
        return 0.7;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        if ($context->user === null && $context->ipAddress === null) {
            return SignalResult::none();
        }

        $failures = SecurityEvent::query()
            ->whereIn('type', $this->catalog->failureKeys())
            ->where('occurred_at', '>=', $context->occurredAt->copy()->subMinutes(self::WINDOW_MINUTES))
            ->where(function ($query) use ($context): void {
                if ($context->user !== null) {
                    $query->orWhere('user_id', $context->user->getKey());
                }
                if ($context->ipAddress !== null) {
                    $query->orWhere('ip_address', $context->ipAddress);
                }
            })
            ->count();

        if ($context->definition->isFailure) {
            $failures++;
        }

        if ($failures === 0) {
            return SignalResult::none();
        }

        return new SignalResult($failures / self::FULL_AT, "{$failures} failed attempts in the last ".self::WINDOW_MINUTES.' minutes.');
    }
}
