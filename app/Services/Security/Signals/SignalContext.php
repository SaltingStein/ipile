<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\Security;
use App\Services\Security\SecurityEventDefinition;
use Carbon\CarbonInterface;

/**
 * Everything a signal may look at for the event being scored. The event itself is not saved yet.
 */
final readonly class SignalContext
{
    /**
     * @param  SecurityEvent|null  $previousEvent  The account's last event in the same session (null when the session just started).
     * @param  array<string, scalar|null>  $context  Extra values passed to {@see Security::record()}.
     */
    public function __construct(
        public SecurityEventDefinition $definition,
        public ?User $user,
        public ?string $ipAddress,
        public ?string $deviceHash,
        public ?string $networkHash,
        public CarbonInterface $occurredAt,
        public ?SecurityEvent $previousEvent,
        public array $context,
    ) {}
}
