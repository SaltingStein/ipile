<?php

namespace App\Services\Security\Analysis;

use App\Ai\Agents\SecurityIncidentAnalyst;
use App\Models\SecurityIncident;
use App\Services\Ai\AiModels;
use App\Services\Ai\RetriesAiConnections;
use App\Services\Security\SecuritySettings;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

/**
 * Asks the AI model chosen in Admin → Security → Settings (any model switched on in Admin → Integrations → AI models)
 * to triage an incident through `laravel/ai` with structured output. Only the redacted {@see IncidentBriefing} is sent.
 */
class AiSecurityAnalyst implements SecurityAnalyst
{
    use RetriesAiConnections;

    public function __construct(
        private SecuritySettings $settings,
        private AiModels $models,
        private IncidentBriefing $briefing,
    ) {}

    public function isAvailable(): bool
    {
        $provider = $this->settings->analysisProvider();

        return $provider !== null && array_key_exists($provider, $this->models->enabled());
    }

    public function analyze(SecurityIncident $incident): SecurityAnalysis
    {
        $key = $this->settings->analysisProvider();
        $provider = $key === null ? null : $this->models->provider($key);

        if ($provider === null) {
            throw new SecurityAnalysisException(__('No AI model is chosen for incident analysis. Choose one in Admin → Security → Settings.'));
        }

        $briefing = "Incident briefing:\n".json_encode($this->briefing->for($incident), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->withConnectionRetries(fn () => (new SecurityIncidentAnalyst)->prompt($briefing, provider: [$provider], timeout: 120));
        } catch (InsufficientCreditsException) {
            throw new SecurityAnalysisException(__('The AI model has no credit left.'));
        } catch (ProviderConnectionException) {
            throw new SecurityAnalysisException(__('Could not reach the AI model.'));
        } catch (Throwable $exception) {
            throw new SecurityAnalysisException(__('The AI model returned an error: :message', ['message' => Str::limit($exception->getMessage(), 300)]));
        }

        if (! in_array($response['verdict'] ?? null, SecurityAnalysis::VERDICTS, true)
            || ! in_array($response['recommended_action'] ?? null, SecurityAnalysis::ACTIONS, true)) {
            throw new SecurityAnalysisException(__('The analysis was not in the expected format.'));
        }

        $steps = is_array($response['suggested_steps'] ?? null) ? $response['suggested_steps'] : [];

        return new SecurityAnalysis(
            verdict: $response['verdict'],
            confidence: max(0.0, min(1.0, (float) ($response['confidence'] ?? 0))),
            summary: mb_substr((string) ($response['summary'] ?? ''), 0, 500),
            explanation: mb_substr((string) ($response['explanation'] ?? ''), 0, 4000),
            recommendedAction: $response['recommended_action'],
            suggestedSteps: array_values(array_map(fn (mixed $step) => mb_substr((string) $step, 0, 500), array_slice($steps, 0, 10))),
            model: (string) ($response->meta->model ?? $key),
        );
    }
}
