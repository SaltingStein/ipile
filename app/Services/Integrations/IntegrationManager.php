<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Services\Assistant\AssistantWidget;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads and writes dashboard-managed integration settings.
 *
 * Drivers call {@see enabledConfig()} to get their decrypted settings; the dashboard uses
 * {@see forDashboard()}, which never includes secret values.
 */
class IntegrationManager
{
    public function __construct(
        private IntegrationCatalog $catalog,
        private Container $container,
    ) {}

    /**
     * The decrypted settings of an enabled provider, or null when it is disabled or not configured.
     * Called on every page (analytics) and for every email and SMS, so rows are read from the cache. The cache
     * holds the raw, still-encrypted attributes; decryption happens here, per call.
     *
     * @return array<string, mixed>|null
     */
    public function enabledConfig(string $provider): ?array
    {
        $integration = $this->cached($provider);

        return $integration?->is_enabled ? $integration->config : null;
    }

    /**
     * The decrypted settings whether or not the provider is enabled, for drivers that must keep reaching
     * data saved while it was on (e.g. files in a storage bucket). Null when nothing is saved.
     *
     * @return array<string, mixed>|null
     */
    public function savedConfig(string $provider): ?array
    {
        return $this->cached($provider)?->config;
    }

    public function isEnabled(string $provider): bool
    {
        return $this->enabledConfig($provider) !== null;
    }

    /**
     * Every provider in the catalog with its saved state, grouped by type. Secret values are replaced by `has_value`.
     *
     * @return list<array<string, mixed>>
     */
    public function forDashboard(): array
    {
        $stored = Integration::query()->get()->keyBy('provider');
        $groups = [];

        foreach ($this->catalog->all() as $definition) {
            $integration = $stored->get($definition->provider);
            $config = $integration?->config ?? [];

            $groups[$definition->type->value]['type'] = $definition->type->value;
            $groups[$definition->type->value]['label'] = $definition->type->label();
            $groups[$definition->type->value]['providers'][] = [
                'provider' => $definition->provider,
                'label' => $definition->label,
                'description' => $definition->description,
                'is_enabled' => (bool) $integration?->is_enabled,
                'can_test' => $definition->tester !== null,
                'links' => $definition->links,
                'fields' => array_map(fn (IntegrationField $field) => [
                    ...$field->toArray(),
                    'value' => $field->secret ? null : ($config[$field->name] ?? null),
                    'has_value' => filled($config[$field->name] ?? null),
                ], $definition->fields),
                'last_test' => $integration?->last_tested_at === null ? null : [
                    'at' => $integration->last_tested_at->toIso8601String(),
                    'succeeded' => $integration->last_test_succeeded,
                    'message' => $integration->last_test_message,
                ],
            ];
        }

        return array_values($groups);
    }

    /**
     * Validation rules for the provider's form.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(IntegrationDefinition $definition): array
    {
        $rules = ['is_enabled' => ['required', 'boolean']];

        foreach ($definition->fields as $field) {
            $rules["config.{$field->name}"] = $field->rules();
        }

        return $rules;
    }

    /**
     * Save validated input. Blank secret fields keep their stored value; every required field must have
     * a value before the provider can be enabled.
     *
     * @param  array{is_enabled: bool, config?: array<string, mixed>}  $input
     *
     * @throws ValidationException
     */
    public function update(IntegrationDefinition $definition, array $input): Integration
    {
        $integration = Integration::query()->firstOrNew(['provider' => $definition->provider]);
        $config = $integration->config ?? [];

        foreach ($definition->fields as $field) {
            $value = $input['config'][$field->name] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($field->secret && blank($value)) {
                continue;
            }

            $config[$field->name] = blank($value) ? null : $value;
        }

        $isEnabled = (bool) $input['is_enabled'];

        if ($isEnabled && $definition->provider === AssistantWidget::PROVIDER) {
            AssistantWidget::validateConfig($config);
        }

        if ($isEnabled) {
            $missing = [];
            foreach ($definition->fields as $field) {
                if ($field->required && blank($config[$field->name] ?? null)) {
                    $missing["config.{$field->name}"] = __(':field is required to enable :provider.', ['field' => $field->label, 'provider' => $definition->label]);
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        $integration->fill(['type' => $definition->type->value, 'is_enabled' => $isEnabled]);
        $integration->config = $config;
        $integration->save();

        return $integration;
    }

    /**
     * Run the provider's connection test with its saved settings and record the outcome.
     */
    public function test(IntegrationDefinition $definition): IntegrationTestResult
    {
        $integration = Integration::query()->where('provider', $definition->provider)->first();

        $result = match (true) {
            $definition->tester === null => IntegrationTestResult::unavailable(__('A connection test for :provider is not available yet.', ['provider' => $definition->label])),
            $integration === null || $integration->config === null => IntegrationTestResult::failed(__('Save the :provider settings before testing.', ['provider' => $definition->label])),
            default => $this->runTester($definition, $integration->config),
        };

        $integration?->forceFill([
            'last_tested_at' => now(),
            'last_test_succeeded' => $result->succeeded,
            'last_test_message' => mb_substr($result->message, 0, 500),
        ])->save();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runTester(IntegrationDefinition $definition, array $config): IntegrationTestResult
    {
        try {
            return $this->container->make($definition->tester)->test($config);
        } catch (Throwable $exception) {
            // Provider SDK errors can include request details, so only a generic message reaches the browser.
            Log::warning('Integration connection test failed.', ['provider' => $definition->provider, 'exception' => $exception]);

            return IntegrationTestResult::failed(__('The connection test failed. Check the settings and try again.'));
        }
    }

    private function cached(string $provider): ?Integration
    {
        $rows = Cache::rememberForever(Integration::CACHE_KEY, fn () => Integration::query()
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->provider => (array) $row])
            ->all());

        return isset($rows[$provider]) ? (new Integration)->newFromBuilder($rows[$provider]) : null;
    }
}
