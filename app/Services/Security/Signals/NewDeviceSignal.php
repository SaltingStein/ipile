<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;

/**
 * The browser or app (user agent) has not been used with this account during the retention period.
 * Silent for accounts with fewer than three earlier events, so new accounts are not flagged.
 */
class NewDeviceSignal implements SecuritySignal
{
    private const MIN_HISTORY = 3;

    public function key(): string
    {
        return 'new_device';
    }

    public function label(): string
    {
        return 'New device';
    }

    public function description(): string
    {
        return 'The browser or app has not been seen on this account before (needs at least three earlier events).';
    }

    public function weight(): float
    {
        return 0.35;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        if ($context->user === null || $context->deviceHash === null) {
            return SignalResult::none();
        }

        $history = SecurityEvent::query()->where('user_id', $context->user->getKey());

        if ((clone $history)->count() < self::MIN_HISTORY) {
            return SignalResult::none();
        }

        if ((clone $history)->where('device_hash', $context->deviceHash)->exists()) {
            return SignalResult::none();
        }

        return new SignalResult(1.0, 'First time this browser or app has been used with the account.');
    }
}
