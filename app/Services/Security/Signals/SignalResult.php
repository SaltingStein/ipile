<?php

namespace App\Services\Security\Signals;

/**
 * A signal's verdict on one event: a 0-1 score and an optional plain-language reason.
 */
final readonly class SignalResult
{
    public float $score;

    public function __construct(float $score, public ?string $detail = null)
    {
        $this->score = max(0.0, min(1.0, $score));
    }

    public static function none(): self
    {
        return new self(0.0);
    }
}
