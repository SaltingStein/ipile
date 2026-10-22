<?php

namespace App\Services\Security\Signals;

use InvalidArgumentException;

/**
 * Base for the configurable building blocks ({@see VelocitySignal}, {@see ThresholdSignal}, {@see TimeWindowSignal}):
 * a signal that only looks at the event types the builder named and scores nothing for any other event.
 *
 * @api
 */
abstract class ScopedSignal implements SecuritySignal
{
    /** @var list<string> */
    protected array $types;

    /**
     * @param  string|list<string>  $types  Event keys, e.g. `payouts.requested`.
     */
    public function __construct(string|array $types, protected float $weight)
    {
        $this->types = array_values(array_unique((array) $types));

        if ($this->types === []) {
            throw new InvalidArgumentException(static::class.' needs at least one event type.');
        }

        if ($weight < 0 || $weight > 1) {
            throw new InvalidArgumentException(static::class.' weight must be between 0 and 1.');
        }
    }

    public function weight(): float
    {
        return $this->weight;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        return in_array($context->definition->key, $this->types, true) ? $this->score($context) : SignalResult::none();
    }

    /**
     * Score an event of one of the named types.
     */
    abstract protected function score(SignalContext $context): SignalResult;

    protected function typeList(): string
    {
        return implode(', ', $this->types);
    }
}
