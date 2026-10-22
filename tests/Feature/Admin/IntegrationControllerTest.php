<?php

namespace Tests\Feature\Admin;

use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationDefinition;
use App\Services\Integrations\IntegrationField;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use App\Services\Integrations\IntegrationType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class IntegrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Providers with a real connection test must never reach the network from this suite.
        Http::preventStrayRequests();
    }

    public function test_users_without_manage_integrations_are_forbidden(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $settingsManager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($settingsManager)->get(route('admin.integrations.index'))->assertForbidden();
        $this->actingAs($settingsManager)->get(route('admin.integrations.show', 'paystack'))->assertForbidden();
        $this->actingAs($settingsManager)
            ->put(route('admin.integrations.update', 'paystack'), ['is_enabled' => false, 'config' => ['public_key' => 'pk']])
            ->assertForbidden();

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_page_lists_providers_by_type_without_revealing_secrets(): void
    {
        $this->storePaystack(['public_key' => 'pk_live_visible', 'secret_key' => 'sk_live_hidden'], enabled: true);

        $response = $this->actingAs($this->integrationsManager())->get(route('admin.integrations.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->where('groups.0.type', 'payment')
            ->where('groups.0.providers.0.provider', 'paystack')
            ->where('groups.0.providers.0.is_enabled', true)
            ->where('groups.0.providers.0.fields.0.value', 'pk_live_visible')
            ->where('groups.0.providers.0.fields.1.value', null)
            ->where('groups.0.providers.0.fields.1.has_value', true)
            ->where('groups.1.type', 'sms')
            ->where('groups.2.type', 'mail'));
        $response->assertDontSee('sk_live_hidden');
    }

    public function test_provider_page_shows_a_single_provider_without_revealing_secrets(): void
    {
        $this->storePaystack(['public_key' => 'pk_live_visible', 'secret_key' => 'sk_live_hidden'], enabled: true);

        $response = $this->actingAs($this->integrationsManager())->get(route('admin.integrations.show', 'paystack'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/IntegrationProvider')
            ->where('provider.provider', 'paystack')
            ->where('provider.label', 'Paystack')
            ->where('provider.fields.0.value', 'pk_live_visible')
            ->where('provider.fields.1.value', null)
            ->where('provider.fields.1.has_value', true));
        $response->assertDontSee('sk_live_hidden');
    }

    public function test_sms_provider_page_shows_how_to_get_a_telko_account(): void
    {
        $response = $this->actingAs($this->integrationsManager())->get(route('admin.integrations.show', 'smpp'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/IntegrationProvider')
            ->where('provider.label', 'TelKo SMS (SMPP)')
            ->where('provider.links.0', ['label' => 'Request a TelKo SMS account', 'url' => 'https://telko-ms.com/contact'])
            ->where('provider.links', fn ($links) => collect($links)->pluck('url')->every(
                fn (string $url) => preg_match('#^(https://|mailto:|tel:)#', $url) === 1
            )));
    }

    public function test_providers_without_account_links_send_an_empty_list(): void
    {
        $response = $this->actingAs($this->integrationsManager())->get(route('admin.integrations.show', 'paystack'));

        $response->assertInertia(fn (Assert $page) => $page->where('provider.links', []));
    }

    public function test_saving_stores_the_settings_encrypted(): void
    {
        $response = $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'paystack'), [
            'is_enabled' => true,
            'config' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_456'],
        ]);

        $response->assertSessionHas('status', 'Paystack settings saved.');
        $this->assertSame(
            ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_456', 'callback_url' => null],
            app(IntegrationManager::class)->enabledConfig('paystack'),
        );
        $raw = Integration::query()->toBase()->where('provider', 'paystack')->value('config');
        $this->assertStringNotContainsString('sk_test_456', $raw);
    }

    public function test_blank_secret_keeps_the_stored_secret(): void
    {
        $this->storePaystack(['public_key' => 'pk_old', 'secret_key' => 'sk_stored'], enabled: true);

        $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'paystack'), [
            'is_enabled' => true,
            'config' => ['public_key' => 'pk_new', 'secret_key' => ''],
        ]);

        $this->assertSame(['public_key' => 'pk_new', 'secret_key' => 'sk_stored', 'callback_url' => null], app(IntegrationManager::class)->enabledConfig('paystack'));
    }

    public function test_enabling_without_required_fields_is_refused(): void
    {
        $response = $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'paystack'), [
            'is_enabled' => true,
            'config' => ['public_key' => 'pk_test_123', 'secret_key' => ''],
        ]);

        $response->assertSessionHasErrors(['config.secret_key' => 'Secret key is required to enable Paystack.']);
        $this->assertFalse(app(IntegrationManager::class)->isEnabled('paystack'));
    }

    public function test_disabled_provider_returns_no_config_to_drivers(): void
    {
        $this->storePaystack(['public_key' => 'pk', 'secret_key' => 'sk'], enabled: false);

        $this->assertNull(app(IntegrationManager::class)->enabledConfig('paystack'));
    }

    public function test_invalid_select_option_is_rejected(): void
    {
        $this->actingAs($this->integrationsManager())
            ->put(route('admin.integrations.update', 'opay'), ['is_enabled' => false, 'config' => ['environment' => 'production']])
            ->assertSessionHasErrors(['config.environment' => 'The selected config.environment is invalid.']);
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->actingAs($this->integrationsManager())
            ->get(route('admin.integrations.show', 'stripe'))
            ->assertNotFound();

        $this->actingAs($this->integrationsManager())
            ->put(route('admin.integrations.update', 'stripe'), ['is_enabled' => false])
            ->assertNotFound();
    }

    public function test_provider_without_a_tester_reports_that_testing_is_not_available(): void
    {
        $integration = new Integration(['provider' => 'moniepoint', 'type' => 'payment', 'is_enabled' => false]);
        $integration->config = ['api_key' => 'key', 'secret_key' => 'sk', 'contract_code' => '123', 'environment' => 'sandbox'];
        $integration->save();

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'moniepoint'));

        $response->assertSessionHas('status', 'A connection test for Moniepoint (Monnify) is not available yet.');
        $this->assertNull(Integration::query()->where('provider', 'moniepoint')->value('last_test_succeeded'));
    }

    public function test_passing_connection_test_is_reported_and_recorded(): void
    {
        $this->useAcmeProvider(PassingAcmeTester::class);
        $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'acme'), ['is_enabled' => true, 'config' => ['api_key' => 'acme-key']]);

        $response = $this->post(route('admin.integrations.test', 'acme'));

        $response->assertSessionHas('status', 'Connected as acme-key.');
        $this->assertTrue(Integration::query()->where('provider', 'acme')->value('last_test_succeeded'));
    }

    public function test_tester_exception_shows_a_generic_message_without_details(): void
    {
        $this->useAcmeProvider(ThrowingAcmeTester::class);
        $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'acme'), ['is_enabled' => true, 'config' => ['api_key' => 'acme-key']]);

        $response = $this->post(route('admin.integrations.test', 'acme'));

        $response->assertSessionHasErrors(['acme.test' => 'The connection test failed. Check the settings and try again.']);
        $this->assertFalse(Integration::query()->where('provider', 'acme')->value('last_test_succeeded'));
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
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

    /**
     * @param  class-string<IntegrationTester>  $tester
     */
    private function useAcmeProvider(string $tester): void
    {
        $this->app->instance(IntegrationCatalog::class, new class($tester) extends IntegrationCatalog
        {
            public function __construct(private string $tester) {}

            public function all(): array
            {
                return [
                    ...parent::all(),
                    'acme' => new IntegrationDefinition('acme', IntegrationType::Sms, 'Acme SMS', 'Test provider.', [
                        IntegrationField::secret('api_key', 'API key'),
                    ], $this->tester),
                ];
            }
        });
    }
}

final class PassingAcmeTester implements IntegrationTester
{
    public function test(array $config): IntegrationTestResult
    {
        return IntegrationTestResult::passed("Connected as {$config['api_key']}.");
    }
}

final class ThrowingAcmeTester implements IntegrationTester
{
    public function test(array $config): IntegrationTestResult
    {
        throw new RuntimeException("401 Unauthorized for key {$config['api_key']}");
    }
}
