<?php

namespace Tests\Feature\Services\Security;

use App\Services\Security\Signals\SecuritySignal;
use App\Services\Security\Signals\SignalContext;
use App\Services\Security\Signals\SignalResult;

/**
 * Scores an event with whatever `risk` value the test put in its context.
 */
class ContextRiskSignal implements SecuritySignal
{
    public function key(): string
    {
        return 'context_risk';
    }

    public function label(): string
    {
        return 'Test risk';
    }

    public function description(): string
    {
        return 'Reads the risk from the event context.';
    }

    public function weight(): float
    {
        return 1.0;
    }

    public function evaluate(SignalContext $context): SignalResult
    {
        return new SignalResult((float) ($context->context['risk'] ?? 0));
    }
}
