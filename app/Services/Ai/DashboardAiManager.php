<?php

namespace App\Services\Ai;

use InvalidArgumentException;
use Laravel\Ai\AiManager;

/**
 * The `laravel/ai` manager with a dashboard-backed default: an agent prompted without a provider uses
 * {@see AiModels::defaultProvider()} (the site assistant's AI model, else the first switched-on one) instead of the
 * package's `.env`-based `openai` default. Bound in AppServiceProvider, which also sets `ai.default` to
 * {@see AiModels::DEFAULT_NAME}. Named providers (`provider: 'openai'`) behave exactly as in the package.
 */
class DashboardAiManager extends AiManager
{
    /**
     * @param  string  $name
     * @return array<string, mixed>
     */
    public function getInstanceConfig($name): array
    {
        if ($name !== AiModels::DEFAULT_NAME) {
            return parent::getInstanceConfig($name);
        }

        $config = $this->app->make(AiModels::class)->defaultSdkConfig();

        if ($config !== null) {
            return $config;
        }

        // Faked agents never reach a provider, so tests can prompt them before any AI model is switched on.
        if ($this->fakeAgentGateways !== []) {
            return ['name' => $name, 'driver' => 'openai', 'key' => 'faked'];
        }

        throw new InvalidArgumentException('No AI model is switched on. Enable one in Admin → Integrations → AI models, or pass a provider from AiModels::provider().');
    }
}
