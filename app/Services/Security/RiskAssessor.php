<?php

namespace App\Services\Security;

use App\Services\Security\Signals\SecuritySignals;
use App\Services\Security\Signals\SignalContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scores one event from its signals.
 *
 *     evidence = 1 − Π(1 − weightᵢ × scoreᵢ)            (noisy-OR over every signal)
 *     score    = 100 × evidence × (0.4 + 0.6 × sensitivity)
 *
 * so the same evidence matters more on a sensitive action (turning off two-factor) than a harmless one (signing out).
 */
class RiskAssessor
{
    public function __construct(private SecuritySignals $signals) {}

    /**
     * @return array{score: int, signals: list<array{key: string, label: string, score: float, weight: float, detail: ?string}>}
     */
    public function assess(SignalContext $context): array
    {
        $doubt = 1.0;
        $fired = [];

        foreach ($this->signals->all() as $signal) {
            try {
                $result = $signal->evaluate($context);
            } catch (Throwable $exception) {
                // One broken (custom) signal must not stop the others from scoring the event.
                Log::warning('Security signal failed.', ['signal' => $signal::class, 'exception' => $exception]);

                continue;
            }

            if ($result->score <= 0.0) {
                continue;
            }

            $weight = max(0.0, min(1.0, $signal->weight()));
            $doubt *= 1 - $weight * $result->score;
            $fired[] = [
                'key' => $signal->key(),
                'label' => $signal->label(),
                'score' => round($result->score, 3),
                'weight' => $weight,
                'detail' => $result->detail,
            ];
        }

        $sensitivityFactor = 0.4 + 0.6 * $context->definition->sensitivity;

        return [
            'score' => (int) round(100 * (1 - $doubt) * $sensitivityFactor),
            'signals' => $fired,
        ];
    }
}
