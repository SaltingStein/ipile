<?php

namespace Tests\Feature\Services\Mail;

use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use App\Services\Integrations\IntegrationDefinition;
use App\Services\Integrations\IntegrationField;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\IntegrationType;
use App\Services\Mail\MailProviders;
use App\Services\Mail\ResendClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Which email provider the `dashboard` mailer uses (MailProviders) and the choice in Admin → Integrations.
 */
class MailProviderChoiceTest extends TestCase
{
    use RefreshDatabase;

    private const ZEPTOMAIL_ENDPOINT = 'https://cpaas.zoho.com/v1.1/email';

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'dashboard', 'mail.mailers.dashboard.fallback' => 'array']);
        Http::preventStrayRequests();
        Http::fake([
            self::ZEPTOMAIL_ENDPOINT => Http::response(['request_id' => 'req-1']),
            ResendClient::ENDPOINT => Http::response(['id' => 'email-1']),
        ]);
    }

    public function test_without_a_choice_the_first_switched_on_provider_sends(): void
    {
        $this->storeZeptoMail();
        $this->storeResend();

        $this->sendEmail();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::ZEPTOMAIL_ENDPOINT);
    }

    public function test_the_chosen_provider_sends(): void
    {
        $this->storeZeptoMail();
        $this->storeResend();
        Setting::store(MailProviders::PREFERRED, 'resend');

        $this->sendEmail();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === ResendClient::ENDPOINT);
    }

    public function test_a_chosen_provider_that_is_switched_off_is_passed_over(): void
    {
        $this->storeZeptoMail();
        $this->storeResend(enabled: false);
        Setting::store(MailProviders::PREFERRED, 'resend');

        $this->sendEmail();

        Http::assertSent(fn (Request $request) => $request->url() === self::ZEPTOMAIL_ENDPOINT);
    }

    public function test_a_provider_added_by_a_product_can_be_chosen(): void
    {
        $productTransport = new ArrayTransport;
        $this->app->instance(ArrayTransport::class, $productTransport);
        Integrations::define(new IntegrationDefinition('acme_mail', IntegrationType::Mail, 'Acme Mail', 'Test provider.', [
            IntegrationField::secret('api_key', 'API key'),
        ], transport: ArrayTransport::class));
        $this->storeZeptoMail();
        $this->store('acme_mail', ['api_key' => 'acme-key']);
        Setting::store(MailProviders::PREFERRED, 'acme_mail');

        $this->sendEmail();

        $this->assertCount(1, $productTransport->messages());
        Http::assertNothingSent();
    }

    public function test_manager_chooses_the_provider_on_the_integrations_page(): void
    {
        $this->storeZeptoMail();
        $manager = $this->integrationsManager();

        $this->actingAs($manager)->put(route('admin.integrations.mail-provider'), ['provider' => 'resend'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Email delivery saved.');

        $this->actingAs($manager)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('mailDelivery.preferred', 'resend')
                ->where('mailDelivery.active', 'zeptomail')
                ->where('mailDelivery.providers.1', ['provider' => 'resend', 'label' => 'Resend', 'is_enabled' => false]));
    }

    public function test_automatic_clears_the_choice(): void
    {
        Setting::store(MailProviders::PREFERRED, 'resend');

        $this->actingAs($this->integrationsManager())->put(route('admin.integrations.mail-provider'), ['provider' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::valueOf(MailProviders::PREFERRED));
    }

    public function test_only_email_providers_can_be_chosen(): void
    {
        $this->actingAs($this->integrationsManager())->put(route('admin.integrations.mail-provider'), ['provider' => 'paystack'])
            ->assertSessionHasErrors('provider');

        $this->assertNull(Setting::valueOf(MailProviders::PREFERRED));
    }

    public function test_choosing_the_provider_needs_manage_integrations(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $settingsManager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($settingsManager)->put(route('admin.integrations.mail-provider'), ['provider' => 'resend'])->assertForbidden();

        $this->assertNull(Setting::valueOf(MailProviders::PREFERRED));
    }

    private function sendEmail(): void
    {
        Mail::raw('Hello', fn (Message $message) => $message->to('ada@example.com')->subject('Hi'));
    }

    private function storeZeptoMail(): void
    {
        $this->store('zeptomail', ['api_token' => 'zepto-token', 'api_host' => 'cpaas.zoho.com', 'from_address' => 'noreply@example.com', 'from_name' => 'Ìpìlẹ̀']);
    }

    private function storeResend(bool $enabled = true): void
    {
        $this->store('resend', ['api_key' => 're_test_key', 'from_address' => 'noreply@example.com', 'from_name' => 'Ìpìlẹ̀'], $enabled);
    }

    /**
     * @param  array<string, string>  $config
     */
    private function store(string $provider, array $config, bool $enabled = true): void
    {
        $integration = new Integration(['provider' => $provider, 'type' => 'mail', 'is_enabled' => $enabled]);
        $integration->config = $config;
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
