<?php

namespace App\Services\Ai;

use App\Services\Assistant\AssistantSettings;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\IntegrationType;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * Turns the AI model providers configured in Admin → Integrations → AI models into `laravel/ai` providers at runtime,
 * so keys, URLs and models come from the dashboard and never from `.env`.
 *
 *     $provider = app(AiModels::class)->provider('openai');   // null when it is switched off
 *     (new MyAgent)->prompt('…', provider: [$provider]);
 *
 * An agent prompted without a provider uses {@see defaultProvider()} (see {@see DashboardAiManager}).
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

    /**
     * The SDK's default provider name (`ai.default`), resolved from the dashboard on use.
     */
    public const DEFAULT_NAME = 'ipile-default';

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
     * The provider key agents use when none is given: the site assistant's AI model when it is switched on, otherwise
     * the first switched-on AI model; null when none is.
     */
    public function defaultProvider(): ?string
    {
        $enabled = $this->enabled();
        $chosen = app(AssistantSettings::class)->provider();

        return $chosen !== null && isset($enabled[$chosen]) ? $chosen : (array_key_first($enabled) ?? null);
    }

    /**
     * Build a provider from field values (used by "Test connection", which also runs before a provider is enabled).
     * A blank model falls back to the SDK's default for that provider.
     *
     * @param  array<string, mixed>  $config
     */
    public function fromConfig(string $provider, array $config): TextProvider
    {
        /** @var TextProvider */
        return Ai::build($this->sdkConfig($provider, $config));
    }

    /**
     * The `laravel/ai` provider configuration for saved field values.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function sdkConfig(string $provider, array $config, ?string $name = null): array
    {
        $model = trim((string) ($config['model'] ?? ''));
        $url = trim((string) ($config['url'] ?? ''));

        return array_filter([
            'name' => $name ?? "ipile-{$provider}",
            'driver' => self::DRIVERS[$provider],
            'key' => (string) ($config['api_key'] ?? ''),
            'url' => $url !== '' ? rtrim($url, '/') : null,
            'models' => $model !== '' ? ['text' => ['default' => $model]] : null,
            // Anthropic keys that are not tied to a workspace must name one on every request.
            'headers' => $provider === 'anthropic' && AnthropicApi::workspaceId($config) !== null
                ? [AnthropicApi::WORKSPACE_HEADER => AnthropicApi::workspaceId($config)]
                : null,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * The `laravel/ai` configuration of {@see defaultProvider()}, or null when no AI model is switched on.
     *
     * @return array<string, mixed>|null
     */
    public function defaultSdkConfig(): ?array
    {
        $provider = $this->defaultProvider();
        $config = $provider === null ? null : $this->integrations->enabledConfig($provider);

        return $config === null ? null : $this->sdkConfig($provider, $config, self::DEFAULT_NAME);
    }
}
