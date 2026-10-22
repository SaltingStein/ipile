<?php

namespace App\Services\Security;

/**
 * Whether Aegis runs, and whether it may act on what it finds.
 */
enum SecurityMode: string
{
    case Off = 'off';
    case Monitor = 'monitor';
    case Enforce = 'enforce';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Monitor => 'Monitor only',
            self::Enforce => 'Monitor and respond',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Off => 'Nothing is recorded or scored. Existing events are kept until the retention period ends.',
            self::Monitor => 'Events are recorded and scored, and incidents are opened, but nobody is notified, challenged or signed out. Start here.',
            self::Enforce => 'As monitor, plus the responses below happen automatically when an event crosses their threshold.',
        };
    }

    public function isRecording(): bool
    {
        return $this !== self::Off;
    }
}
