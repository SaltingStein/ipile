<?php

namespace App\Services\Ai;

use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\IntegrationType;
use App\Services\Security\Analysis\ClaudeSecurityAnalyst;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * Turns the AI model providers configured in Admin → Integrations → AI models into `laravel/ai` providers at runtime,
 * so keys, URLs and models come from the dashboard and never from `.env`.
 *
 *     $provider = app(AiModels::class)->provider('openai');   // null when it is switched off
 *     (new MyAgent)->prompt('…', provider: [$provider]);
 */
class AiModels
{
    /**
     * Integration provider key => `laravel/ai` driver.
     */
    public const DRIVERS = [
        'anthropic' => 'anthropic',
        'openai' => 'openai',
        'gemini' => 'gemini',
        'openrouter' => 'openrouter',
        'ollama' => 'ollama',
        'openai_compatible' => 'openai-compatible',
    ];

    public function __construct(
        private IntegrationManager $integrations,
        private IntegrationCatalog $catalog,
    ) {}

    /**
     * Enabled AI model providers, as provider key => label.
     *
     * @return array<string, string>
     */
    public function enabled(): array
    {
        $enabled = [];

        foreach ($this->catalog->all() as $definition) {
            if ($definition->type === IntegrationType::Ai && isset(self::DRIVERS[$definition->provider]) && $this->integrations->isEnabled($definition->provider)) {
                $enabled[$definition->provider] = $definition->label;
            }
        }

        return $enabled;
    }

    /**
     * The provider built from its saved dashboard settings, or null when it is unknown or switched off.
     */
    public function provider(string $provider): ?TextProvider
    {
        $config = isset(self::DRIVERS[$provider]) ? $this->integrations->enabledConfig($provider) : null;

        return $config === null ? null : $this->fromConfig($provider, $config);
    }

    /**
     * Build a provider from field values (used by "Test connection", which also runs before a provider is enabled).
     * A blank model falls back to the SDK's default for that provider.
     *
     * @param  array<string, mixed>  $config
     */
    public function fromConfig(string $provider, array $config): TextProvider
    {
        $model = trim((string) ($config['model'] ?? ''));
        $url = trim((string) ($config['url'] ?? ''));

        /** @var TextProvider */
        return Ai::build(array_filter([
            'name' => "ipile-{$provider}",
            'driver' => self::DRIVERS[$provider],
            'key' => (string) ($config['api_key'] ?? ''),
            'url' => $url !== '' ? rtrim($url, '/') : null,
            'models' => $model !== '' ? ['text' => ['default' => $model]] : null,
            // Anthropic keys that are not tied to a workspace must name one on every request.
            'headers' => $provider === 'anthropic' && ClaudeSecurityAnalyst::workspaceId($config) !== null
                ? [ClaudeSecurityAnalyst::WORKSPACE_HEADER => ClaudeSecurityAnalyst::workspaceId($config)]
                : null,
        ], fn (mixed $value) => $value !== null));
    }
}
