<?php

namespace App\Services\Security\Signals;

use App\Services\Security\Markov\BaselineStore;
use App\Services\Security\Markov\SequenceAnomalyDetector;
use App\Services\Security\SecurityEventCatalog;

/**
 * The Markov signal: how unusual this event is right after the previous one, for this account, its role and
 * everyone. Scores nothing until `security:train` has built baselines.
 */
class SequenceAnomalySignal implements SecuritySignal
{
    public function __construct(
        private BaselineStore $baselines,
        private SequenceAnomalyDetector $detector,
        private SecurityEventCatalog $catalog,
    ) {}

    public function key(): string
    {
        return 'sequence_anomaly';
    }

    public function label(): string
    {
        return 'Unusual sequence';
    }

    public function description(): string
    {
        return 'How unlikely this event is straight after the previous one, compared with this account\'s own history, its role, and all accounts (Markov chain).';
    }

    public function weight(): float
    {
        return 0.6;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        if ($context->user === null) {
            return SignalResult::none();
        }

        $previous = $context->previousEvent?->type ?? SequenceAnomalyDetector::START;
        $score = $this->detector->anomaly(
            $previous,
            $context->definition->key,
            $this->baselines->scopesFor($context->user),
            array_keys($this->catalog->all()),
        );

        if ($score < 0.05) {
            return SignalResult::none();
        }

        $after = $previous === SequenceAnomalyDetector::START ? 'at the start of a session' : 'after "'.$this->catalog->label($previous).'"';

        return new SignalResult($score, "Rarely seen {$after}.");
    }
}
