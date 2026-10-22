<?php

namespace App\Services\Security\Signals;

use DateTimeZone;
use InvalidArgumentException;

/**
 * "At an odd hour": the named events happened outside the hours you expect them, in your timezone.
 *
 *     Security::signal(TimeWindowSignal::for('payouts.requested', outside: '06:00-22:00', timezone: 'Africa/Lagos'));
 *
 * A window may cross midnight ('22:00-06:00' expects night-time activity). No database query.
 *
 * @api
 */
class TimeWindowSignal extends ScopedSignal
{
    private int $from;

    private int $until;

    private string $timezone;

    /**
     * @param  string|list<string>  $types
     * @param  string  $outside  The expected hours as `HH:MM-HH:MM`; events outside them score.
     */
    public function __construct(string|array $types, private string $outside, ?string $timezone = null, private float $score = 1.0, float $weight = 0.4)
    {
        parent::__construct($types, $weight);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $outside, $parts) !== 1 || $parts[1].$parts[2] === $parts[3].$parts[4]) {
            throw new InvalidArgumentException("TimeWindowSignal expects hours like 06:00-22:00; [{$outside}] is not.");
        }

        $this->timezone = $timezone ?? (string) config('app.timezone');

        if (! in_array($this->timezone, DateTimeZone::listIdentifiers(), true) && $this->timezone !== 'UTC') {
            throw new InvalidArgumentException("[{$this->timezone}] is not a timezone.");
        }

        if ($score <= 0 || $score > 1) {
            throw new InvalidArgumentException('TimeWindowSignal score must be above 0 and at most 1.');
        }

        $this->from = (int) $parts[1] * 60 + (int) $parts[2];
        $this->until = (int) $parts[3] * 60 + (int) $parts[4];
    }

    /**
     * @param  string|list<string>  $types
     */
    public static function for(string|array $types, string $outside, ?string $timezone = null, float $score = 1.0, float $weight = 0.4): self
    {
        return new self($types, $outside, $timezone, $score, $weight);
    }

    public function key(): string
    {
        return 'time_window:'.implode(',', $this->types);
    }

    public function label(): string
    {
        return 'At an odd hour';
    }

    public function description(): string
    {
        return "{$this->typeList()} outside {$this->outside} ({$this->timezone}).";
    }

    protected function score(SignalContext $context): SignalResult
    {
        $local = $context->occurredAt->copy()->setTimezone($this->timezone);
        $minute = $local->hour * 60 + $local->minute;
        $isExpected = $this->from < $this->until
            ? $minute >= $this->from && $minute < $this->until
            : $minute >= $this->from || $minute < $this->until;

        return $isExpected ? SignalResult::none() : new SignalResult($this->score, "At {$local->format('H:i')} ({$this->timezone}), outside {$this->outside}.");
    }
}
