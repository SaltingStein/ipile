<?php

namespace App\Services\Security\Signals;

use App\Models\SecurityEvent;
use InvalidArgumentException;

/**
 * "Unusually large": a number in the event's context is above a limit you set, or many times what this account
 * usually does.
 *
 *     Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', above: 50_000_000));
 *     Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', timesUsual: 5));
 *
 * `timesUsual` compares with the median of the account's last 50 events of the same types (one indexed query) and
 * waits for at least 5 earlier values before it scores anything. Crossing a limit scores 0.6, rising to 1.0 at
 * twice the limit. Events without a numeric value under the key score nothing.
 *
 * @api
 */
class ThresholdSignal extends ScopedSignal
{
    private const HISTORY = 50;

    private const MINIMUM_HISTORY = 5;

    /**
     * @param  string|list<string>  $types
     */
    public function __construct(
        string|array $types,
        private string $contextKey,
        private ?float $above = null,
        private ?float $timesUsual = null,
        float $weight = 0.6,
    ) {
        parent::__construct($types, $weight);

        if ($above === null && $timesUsual === null) {
            throw new InvalidArgumentException('ThresholdSignal needs `above`, `timesUsual` or both.');
        }

        if (($above !== null && $above <= 0) || ($timesUsual !== null && $timesUsual <= 1)) {
            throw new InvalidArgumentException('ThresholdSignal needs `above` greater than 0 and `timesUsual` greater than 1.');
        }
    }

    /**
     * @param  string|list<string>  $types
     */
    public static function on(string|array $types, string $contextKey, ?float $above = null, ?float $timesUsual = null, float $weight = 0.6): self
    {
        return new self($types, $contextKey, $above, $timesUsual, $weight);
    }

    public function key(): string
    {
        return 'threshold:'.implode(',', $this->types).':'.$this->contextKey;
    }

    public function label(): string
    {
        return 'Unusually large';
    }

    public function description(): string
    {
        $limits = array_filter([
            $this->above === null ? null : 'above '.$this->above,
            $this->timesUsual === null ? null : $this->timesUsual.'× the account\'s usual',
        ]);

        return "{$this->contextKey} on {$this->typeList()} ".implode(' or ', $limits).'.';
    }

    protected function score(SignalContext $context): SignalResult
    {
        $value = $context->context[$this->contextKey] ?? null;

        if (! is_numeric($value)) {
            return SignalResult::none();
        }

        $value = (float) $value;
        $best = SignalResult::none();

        if ($this->above !== null && $value > $this->above) {
            $best = $this->exceeded($value, $this->above, "{$value} is above the limit of {$this->above}.");
        }

        $usual = $this->timesUsual === null ? null : $this->usualFor($context);

        if ($usual !== null && $usual > 0 && $value > $this->timesUsual * $usual) {
            $relative = $this->exceeded($value, $this->timesUsual * $usual, "{$value} is more than {$this->timesUsual}× this account's usual {$usual}.");
            $best = $relative->score > $best->score ? $relative : $best;
        }

        return $best;
    }

    private function exceeded(float $value, float $limit, string $detail): SignalResult
    {
        return new SignalResult(0.6 + 0.4 * min(1, $value / $limit - 1), $detail);
    }

    /**
     * Median of the account's earlier values, or null with too little history.
     */
    private function usualFor(SignalContext $context): ?float
    {
        if ($context->user === null) {
            return null;
        }

        $values = SecurityEvent::query()
            ->where('user_id', $context->user->getKey())
            ->whereIn('type', $this->types)
            ->latest('occurred_at')
            ->limit(self::HISTORY)
            ->pluck('context')
            ->map(fn (?array $previous) => $previous[$this->contextKey] ?? null)
            ->filter(fn (mixed $previous) => is_numeric($previous))
            ->map(fn (mixed $previous) => (float) $previous)
            ->sort()
            ->values();

        if ($values->count() < self::MINIMUM_HISTORY) {
            return null;
        }

        return (float) $values->median();
    }
}
