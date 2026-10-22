<?php

namespace App\Services\Security\Analysis;

/**
 * The AI analyst's opinion on an incident.
 */
final readonly class SecurityAnalysis
{
    public const VERDICTS = ['likely_benign', 'suspicious', 'likely_compromised'];

    public const ACTIONS = ['none', 'monitor', 'notify_user', 'step_up', 'revoke_sessions', 'suspend'];

    /**
     * @param  list<string>  $suggestedSteps
     */
    public function __construct(
        public string $verdict,
        public float $confidence,
        public string $summary,
        public string $explanation,
        public string $recommendedAction,
        public array $suggestedSteps,
        public string $model,
    ) {}

    /**
     * @return array{verdict: string, confidence: float, summary: string, explanation: string, recommended_action: string, suggested_steps: list<string>, model: string}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'explanation' => $this->explanation,
            'recommended_action' => $this->recommendedAction,
            'suggested_steps' => $this->suggestedSteps,
            'model' => $this->model,
        ];
    }
}
