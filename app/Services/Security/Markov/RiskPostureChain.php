<?php

namespace App\Services\Security\Markov;

/**
 * A four-level Markov chain for an account's risk posture: normal → elevated → high → critical.
 *
 * The account's state is a probability over the levels (a belief), not a single level. Each event moves it with
 * the transition matrix for that event's risk band, and quiet time moves it back toward normal. Several
 * moderately risky events in a row therefore add up, while a single odd event fades.
 */
class RiskPostureChain
{
    public const LEVELS = ['normal', 'elevated', 'high', 'critical'];

    /** Score (0-100) each level contributes to the posture score. */
    private const LEVEL_SCORES = [0, 35, 65, 90];

    /** One step of the "calm" matrix is applied per this many quiet minutes. */
    public const DECAY_STEP_MINUTES = 30;

    /** At most one day of decay is applied at once; after that the account is effectively back to normal. */
    private const MAX_DECAY_STEPS = 48;

    /**
     * Band => row-stochastic matrix (from level => probability of each next level).
     *
     * @var array<string, list<list<float>>>
     */
    private const MATRICES = [
        'calm' => [
            [1.00, 0.00, 0.00, 0.00],
            [0.30, 0.70, 0.00, 0.00],
            [0.05, 0.25, 0.70, 0.00],
            [0.00, 0.05, 0.15, 0.80],
        ],
        'low' => [
            [0.85, 0.15, 0.00, 0.00],
            [0.10, 0.75, 0.15, 0.00],
            [0.00, 0.10, 0.80, 0.10],
            [0.00, 0.00, 0.10, 0.90],
        ],
        'medium' => [
            [0.50, 0.40, 0.10, 0.00],
            [0.00, 0.50, 0.40, 0.10],
            [0.00, 0.00, 0.60, 0.40],
            [0.00, 0.00, 0.00, 1.00],
        ],
        'high' => [
            [0.10, 0.30, 0.40, 0.20],
            [0.00, 0.10, 0.50, 0.40],
            [0.00, 0.00, 0.30, 0.70],
            [0.00, 0.00, 0.00, 1.00],
        ],
    ];

    /**
     * @return list<float>
     */
    public function initial(): array
    {
        return [1.0, 0.0, 0.0, 0.0];
    }

    /**
     * Apply quiet-time decay for the minutes since the last event.
     *
     * @param  list<float>  $belief
     * @return list<float>
     */
    public function decay(array $belief, float $minutesElapsed): array
    {
        $steps = min(self::MAX_DECAY_STEPS, (int) floor(max(0, $minutesElapsed) / self::DECAY_STEP_MINUTES));

        for ($step = 0; $step < $steps; $step++) {
            $belief = $this->step($belief, self::MATRICES['calm']);
        }

        return $belief;
    }

    /**
     * Move the belief for one event with the given risk score.
     *
     * @param  list<float>  $belief
     * @return list<float>
     */
    public function observe(array $belief, int $eventScore): array
    {
        return $this->step($belief, self::MATRICES[$this->band($eventScore)]);
    }

    /**
     * Expected posture score (0-100) for a belief.
     *
     * @param  list<float>  $belief
     */
    public function score(array $belief): int
    {
        $score = 0.0;
        foreach (self::LEVEL_SCORES as $index => $levelScore) {
            $score += ($belief[$index] ?? 0.0) * $levelScore;
        }

        return (int) round($score);
    }

    /**
     * The level name used for display at a given score.
     */
    public static function levelFor(int $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 60 => 'high',
            $score >= 30 => 'elevated',
            default => 'normal',
        };
    }

    /**
     * A belief read from storage, or the initial one if it is missing or malformed.
     *
     * @return list<float>
     */
    public function normalise(mixed $belief): array
    {
        if (! is_array($belief) || count($belief) !== count(self::LEVELS)) {
            return $this->initial();
        }

        $values = array_map(fn (mixed $value) => max(0.0, (float) $value), array_values($belief));
        $total = array_sum($values);

        return $total > 0 ? array_map(fn (float $value) => $value / $total, $values) : $this->initial();
    }

    private function band(int $eventScore): string
    {
        return match (true) {
            $eventScore >= 75 => 'high',
            $eventScore >= 50 => 'medium',
            $eventScore >= 30 => 'low',
            default => 'calm',
        };
    }

    /**
     * @param  list<float>  $belief
     * @param  list<list<float>>  $matrix
     * @return list<float>
     */
    private function step(array $belief, array $matrix): array
    {
        $next = array_fill(0, count(self::LEVELS), 0.0);

        foreach ($belief as $from => $probability) {
            foreach ($matrix[$from] as $to => $transition) {
                $next[$to] += $probability * $transition;
            }
        }

        return $next;
    }
}
