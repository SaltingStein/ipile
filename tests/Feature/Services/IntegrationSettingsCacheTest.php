<?php

namespace Tests\Feature\Services;

use App\Models\Integration;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationSettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_config_is_read_from_the_cache_after_the_first_call(): void
    {
        $this->storePaystack(['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret'], enabled: true);
        $integrations = app(IntegrationManager::class);
        $integrations->enabledConfig('paystack');

        DB::enableQueryLog();
        $config = $integrations->enabledConfig('paystack');
        $integrations->enabledConfig('zeptomail');

        $this->assertSame('sk_test_secret', $config['secret_key']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_saving_from_the_dashboard_takes_effect_immediately(): void
    {
        $this->storePaystack(['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret'], enabled: true);
        $integrations = app(IntegrationManager::class);
        $this->assertNotNull($integrations->enabledConfig('paystack'));

        $integrations->update(app(IntegrationCatalog::class)->find('paystack'), ['is_enabled' => false, 'config' => []]);
        $this->assertNull($integrations->enabledConfig('paystack'));

        $integrations->update(app(IntegrationCatalog::class)->find('paystack'), ['is_enabled' => true, 'config' => ['public_key' => 'pk_rotated']]);
        $this->assertSame('pk_rotated', $integrations->enabledConfig('paystack')['public_key']);
    }

    public function test_secrets_stay_encrypted_in_the_cache_store(): void
    {
        $this->storePaystack(['public_key' => 'pk_test', 'secret_key' => 'sk_test_secret'], enabled: true);
        app(IntegrationManager::class)->enabledConfig('paystack');

        $cached = serialize(Cache::get(Integration::CACHE_KEY));

        $this->assertStringContainsString('paystack', $cached);
        $this->assertStringNotContainsString('sk_test_secret', $cached);
    }

    /**
     * @param  array<string, string>  $config
     */
    private function storePaystack(array $config, bool $enabled): void
    {
        $integration = new Integration(['provider' => 'paystack', 'type' => 'payment', 'is_enabled' => $enabled]);
        $integration->config = $config;
        $integration->save();
    }
}
