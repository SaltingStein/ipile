<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;

/**
 * The network (IPv4 /24 or IPv6 /48) has not been used with this account during the retention period.
 * Mobile networks change addresses often, so this signal is deliberately weaker than a new device.
 */
class NewNetworkSignal implements SecuritySignal
{
    private const MIN_HISTORY = 3;

    public function key(): string
    {
        return 'new_network';
    }

    public function label(): string
    {
        return 'New network';
    }

    public function description(): string
    {
        return 'The IP address range (IPv4 /24, IPv6 /48) has not been seen on this account before.';
    }

    public function weight(): float
    {
        return 0.25;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        if ($context->user === null || $context->networkHash === null) {
            return SignalResult::none();
        }

        $history = SecurityEvent::query()->where('user_id', $context->user->getKey());

        if ((clone $history)->count() < self::MIN_HISTORY) {
            return SignalResult::none();
        }

        if ((clone $history)->where('network_hash', $context->networkHash)->exists()) {
            return SignalResult::none();
        }

        return new SignalResult(1.0, 'First time this network has been used with the account.');
    }
}
