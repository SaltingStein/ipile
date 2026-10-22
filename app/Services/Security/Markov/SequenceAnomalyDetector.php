<?php

namespace App\Services\Security\Markov;

/**
 * Scores how surprising an event is, given the event before it, using a first-order Markov chain.
 *
 * The probability of each possible next event is blended across scopes (everyone → role → account, see
 * {@see TransitionMatrix}). Surprise is measured relative to the most likely next event rather than in absolute
 * terms: with no history every event is equally likely, so nothing looks anomalous until there is data.
 */
class SequenceAnomalyDetector
{
    /** The state before the first event of a session. */
    public const START = '__start__';

    /** An observed event this many times less likely than the most likely one scores 1.0. */
    private const SURPRISE_AT_FULL_SCORE = 100;

    /**
     * @param  list<TransitionMatrix>  $scopes  Least specific first (global, then role, then account).
     * @param  list<string>  $vocabulary  Every event key that could come next.
     * @return float 0 (expected) to 1 (highly unusual)
     */
    public function anomaly(string $previous, string $next, array $scopes, array $vocabulary): float
    {
        $vocabulary = array_values(array_unique([...$vocabulary, $next]));
        $probabilities = $this->distribution($previous, $scopes, $vocabulary);
        $mostLikely = max($probabilities);

        if ($mostLikely <= 0.0) {
            return 0.0;
        }

        $relativeLikelihood = $probabilities[$next] / $mostLikely;

        return max(0.0, min(1.0, -log10($relativeLikelihood) / log10(self::SURPRISE_AT_FULL_SCORE)));
    }

    /**
     * The blended probability of every possible next event.
     *
     * @param  list<TransitionMatrix>  $scopes
     * @param  list<string>  $vocabulary
     * @return array<string, float>
     */
    public function distribution(string $previous, array $scopes, array $vocabulary): array
    {
        $uniform = 1 / max(1, count($vocabulary));
        $probabilities = array_fill_keys($vocabulary, $uniform);

        foreach ($scopes as $matrix) {
            foreach ($vocabulary as $next) {
                $probabilities[$next] = $matrix->probability($previous, $next, $probabilities[$next]);
            }
        }

        return $probabilities;
    }
}
