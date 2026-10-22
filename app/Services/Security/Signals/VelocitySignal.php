<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;
use App\Services\Security\Security;
use InvalidArgumentException;

/**
 * "Too many, too fast": the account did more of the named events in the window than you allow.
 *
 *     Security::signal(VelocitySignal::for('payouts.requested', max: 5, perMinutes: 60));
 *
 * Counts this event plus earlier ones of the same types (one indexed query; by IP address when nobody is signed in).
 * The first event over the limit scores 0.5, rising to 1.0 at twice the limit.
 *
 * @api
 */
class VelocitySignal extends ScopedSignal
{
    /**
     * @param  string|list<string>  $types
     */
    public function __construct(string|array $types, private int $max, private int $perMinutes, float $weight = 0.6)
    {
        parent::__construct($types, $weight);

        if ($max < 1 || $perMinutes < 1) {
            throw new InvalidArgumentException('VelocitySignal needs a limit and a window of at least 1.');
        }
    }

    /**
     * @param  string|list<string>  $types
     */
    public static function for(string|array $types, int $max, int $perMinutes, float $weight = 0.6): self
    {
        return new self($types, $max, $perMinutes, $weight);
    }

    public function key(): string
    {
        return 'velocity:'.implode(',', $this->types);
    }

    public function label(): string
    {
        return 'Too many, too fast';
    }

    public function description(): string
    {
        return "More than {$this->max} of {$this->typeList()} within {$this->perMinutes} minutes from one account.";
    }

    protected function score(SignalContext $context): SignalResult
    {
        $query = SecurityEvent::query()
            ->whereIn('type', $this->types)
            ->where('occurred_at', '>=', $context->occurredAt->copy()->subMinutes($this->perMinutes));

        if ($context->user !== null) {
            $query->where('user_id', $context->user->getKey());
        } elseif ($context->ipAddress !== null) {
            $query->whereNull('user_id')->where('ip_address', $context->ipAddress);
        } else {
            return SignalResult::none();
        }

        $count = $query->count() + 1;

        if ($count <= $this->max) {
            return SignalResult::none();
        }

        return new SignalResult(
            0.5 + 0.5 * min(1, ($count - $this->max - 1) / max(1, $this->max - 1)),
            "{$count} in the last {$this->perMinutes} minutes (limit {$this->max}).",
        );
    }
}
