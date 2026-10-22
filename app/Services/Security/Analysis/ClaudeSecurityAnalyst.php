<?php

namespace App\Services\Security\Analysis;

use App\Models\SecurityIncident;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks Claude (Anthropic Messages API) to triage an incident, using the key and model saved in
 * Admin → Integrations → Anthropic Claude. The reply is constrained to {@see self::schema()} with structured outputs.
 */
class ClaudeSecurityAnalyst implements SecurityAnalyst
{
    public const PROVIDER = 'anthropic';

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    public const MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    public const API_URL = 'https://api.anthropic.com/v1';

    public const API_VERSION = '2023-06-01';

    /** Sent when the API key is not tied to a workspace (Admin → Integrations → Anthropic Claude → Workspace ID). */
    public const WORKSPACE_HEADER = 'anthropic-workspace-id';

    /** Server-side refusal fallback ("default" routing). Not available for Haiku. */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a security analyst reviewing an incident raised by an automated risk engine in a web application.
        The engine scores each account event with signals (a Markov-chain sequence anomaly, new device, new network,
        bursts of activity, recent failed attempts) and tracks the account's risk posture over time. Its responses
        range from alerting the owner to signing out every session or suspending the account.

        You receive a redacted JSON briefing: no names, emails or IP addresses. Judge whether the activity looks like
        the account owner behaving unusually, or like account takeover, credential stuffing, insider misuse or
        automated abuse. Weigh how sensitive each action is, the order and timing of events, and which signals fired.
        Say plainly when the evidence is thin. An administrator reads your answer and decides what to do; you do not
        act on the account yourself.
        PROMPT;

    public function __construct(
        private IntegrationManager $integrations,
        private IncidentBriefing $briefing,
    ) {}

    public function isAvailable(): bool
    {
        return filled($this->integrations->enabledConfig(self::PROVIDER)['api_key'] ?? null);
    }

    public function analyze(SecurityIncident $incident): SecurityAnalysis
    {
        $config = $this->integrations->enabledConfig(self::PROVIDER);

        if (blank($config['api_key'] ?? null)) {
            throw new SecurityAnalysisException(__('The Anthropic integration is not enabled.'));
        }

        $model = in_array($config['model'] ?? null, self::MODELS, true) ? $config['model'] : self::DEFAULT_MODEL;
        $supportsFallback = $model !== 'claude-haiku-5-5';

        $payload = [
            'model' => $model,
            'max_tokens' => 16000,
            'system' => self::SYSTEM_PROMPT,
            'output_config' => [
                'effort' => 'medium',
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
            'messages' => [[
                'role' => 'user',
                'content' => "Incident briefing:\n".json_encode($this->briefing->for($incident), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ];

        if ($supportsFallback) {
            $payload['fallbacks'] = 'default';
        }

        try {
            $response = Http::withHeaders(array_filter([
                'x-api-key' => $config['api_key'],
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta' => $supportsFallback ? self::FALLBACK_BETA : null,
                self::WORKSPACE_HEADER => self::workspaceId($config),
            ]))->acceptJson()->timeout(120)->post(self::API_URL.'/messages', $payload);
        } catch (ConnectionException) {
            throw new SecurityAnalysisException(__('Could not reach the Anthropic API.'));
        }

        if ($response->failed()) {
            throw new SecurityAnalysisException(__('The Anthropic API returned an error (HTTP :status): :message', [
                'status' => $response->status(),
                'message' => (string) $response->json('error.message', 'no details'),
            ]));
        }

        return $this->parse($response->json() ?? [], $model);
    }

    /**
     * The workspace to use for keys that are not tied to one, or null.
     *
     * @param  array<string, mixed>  $config
     */
    public static function workspaceId(array $config): ?string
    {
        $workspace = trim((string) ($config['workspace_id'] ?? ''));

        return $workspace === '' ? null : $workspace;
    }

    /**
     * JSON schema the reply must follow.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['verdict', 'confidence', 'summary', 'explanation', 'recommended_action', 'suggested_steps'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => SecurityAnalysis::VERDICTS],
                'confidence' => ['type' => 'number', 'description' => 'From 0 (guess) to 1 (certain).'],
                'summary' => ['type' => 'string', 'description' => 'One sentence for the incident list.'],
                'explanation' => ['type' => 'string', 'description' => 'A short paragraph on the evidence for and against.'],
                'recommended_action' => ['type' => 'string', 'enum' => SecurityAnalysis::ACTIONS],
                'suggested_steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Concrete next steps for the administrator.'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function parse(array $body, string $requestedModel): SecurityAnalysis
    {
        $stopReason = $body['stop_reason'] ?? null;

        if ($stopReason === 'refusal') {
            throw new SecurityAnalysisException(__('The model declined to analyse this incident.'));
        }

        if ($stopReason === 'max_tokens') {
            throw new SecurityAnalysisException(__('The analysis was cut off before it finished.'));
        }

        $text = collect($body['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        $result = json_decode($text, true);

        if (! is_array($result)
            || ! in_array($result['verdict'] ?? null, SecurityAnalysis::VERDICTS, true)
            || ! in_array($result['recommended_action'] ?? null, SecurityAnalysis::ACTIONS, true)) {
            throw new SecurityAnalysisException(__('The analysis was not in the expected format.'));
        }

        return new SecurityAnalysis(
            verdict: $result['verdict'],
            confidence: max(0.0, min(1.0, (float) ($result['confidence'] ?? 0))),
            summary: mb_substr((string) ($result['summary'] ?? ''), 0, 500),
            explanation: mb_substr((string) ($result['explanation'] ?? ''), 0, 4000),
            recommendedAction: $result['recommended_action'],
            suggestedSteps: array_values(array_map(
                fn (mixed $step) => mb_substr((string) $step, 0, 500),
                array_slice(is_array($result['suggested_steps'] ?? null) ? $result['suggested_steps'] : [], 0, 10),
            )),
            model: (string) ($body['model'] ?? $requestedModel),
        );
    }
}
