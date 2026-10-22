<?php

namespace App\Services\Security\Markov;

/**
 * Counts of "event A was followed by event B" for one scope (everyone, a role, or one account).
 *
 * Probabilities are smoothed toward a parent distribution (hierarchical Dirichlet smoothing): with no data the
 * matrix simply repeats its parent, and the more often A has been seen, the more its own counts win. This is
 * what lets a brand-new account borrow the behaviour of its role, and a new role borrow from everyone.
 */
final readonly class TransitionMatrix
{
    /** Pseudo-count given to the parent distribution. Higher means a scope needs more data before it counts. */
    public const PRIOR_STRENGTH = 5.0;

    /**
     * @param  array<string, array<string, int>>  $counts  from-state => to-state => times seen
     */
    public function __construct(public array $counts = []) {}

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    /**
     * How many transitions out of a state were seen.
     */
    public function total(string $from): int
    {
        return array_sum($this->counts[$from] ?? []);
    }

    public function count(string $from, string $to): int
    {
        return $this->counts[$from][$to] ?? 0;
    }

    /**
     * P(to | from), smoothed toward the parent's probability for the same transition.
     */
    public function probability(string $from, string $to, float $parentProbability): float
    {
        return ($this->count($from, $to) + self::PRIOR_STRENGTH * $parentProbability)
            / ($this->total($from) + self::PRIOR_STRENGTH);
    }
}
