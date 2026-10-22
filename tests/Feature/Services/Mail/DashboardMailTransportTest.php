<?php

namespace Tests\Feature\Services\Mail;

use App\Models\Integration;
use App\Models\User;
use App\Services\Mail\ZeptoMailException;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Covers the `dashboard` mailer (DashboardMailTransport), ZeptoMailTransport, ZeptoMailClient and ZeptoMailTester.
 */
class DashboardMailTransportTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://cpaas.zoho.com/v1.1/email';

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'dashboard', 'mail.mailers.dashboard.fallback' => 'array']);
        Http::preventStrayRequests();
    }

    public function test_enabled_zeptomail_receives_the_email_with_the_dashboard_sender(): void
    {
        $this->storeZeptoMail(enabled: true);
        Http::fake([self::ENDPOINT => $this->acceptedResponse()]);

        Mail::raw('Your order has shipped.', function (Message $message): void {
            $message->to('ada@example.com', 'Ada Obi')->subject('Order update')->replyTo('support@example.com', 'Support');
        });

        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && $request->header('Authorization') === ['Zoho-enczapikey zepto-token']
            && $request['from'] === ['address' => 'noreply@example.com', 'name' => 'Ìpìlẹ̀']
            && $request['to'] === [['email_address' => ['address' => 'ada@example.com', 'name' => 'Ada Obi']]]
            && $request['reply_to'] === [['address' => 'support@example.com', 'name' => 'Support']]
            && $request['subject'] === 'Order update'
            && $request['textbody'] === 'Your order has shipped.');
        $this->assertSame(0, $this->fallbackMessageCount());
    }

    public function test_password_reset_email_is_delivered_through_zeptomail(): void
    {
        $this->storeZeptoMail(enabled: true);
        Http::fake([self::ENDPOINT => $this->acceptedResponse()]);
        $user = User::factory()->create(['email' => 'ada@example.com']);

        Password::sendResetLink(['email' => $user->email]);

        Http::assertSent(fn (Request $request) => $request['to'][0]['email_address']['address'] === 'ada@example.com'
            && $request['subject'] === 'Reset your password'
            && str_contains($request['htmlbody'], '/reset-password/'));
    }

    public function test_attachments_are_sent_base64_encoded(): void
    {
        $this->storeZeptoMail(enabled: true);
        Http::fake([self::ENDPOINT => $this->acceptedResponse()]);

        Mail::raw('Invoice attached.', function (Message $message): void {
            $message->to('ada@example.com')->subject('Invoice')->attachData('invoice-body', 'invoice.txt', ['mime' => 'text/plain']);
        });

        Http::assertSent(fn (Request $request) => $request['attachments'] === [[
            'content' => base64_encode('invoice-body'),
            'mime_type' => 'text/plain',
            'name' => 'invoice.txt',
        ]]);
    }

    public function test_disabled_zeptomail_falls_back_to_the_env_mailer(): void
    {
        $this->storeZeptoMail(enabled: false);
        Http::fake();

        Mail::raw('Hello', fn (Message $message) => $message->to('ada@example.com')->subject('Hi'));

        Http::assertNothingSent();
        $this->assertSame(1, $this->fallbackMessageCount());
    }

    public function test_enabling_zeptomail_takes_effect_without_rebuilding_the_mailer(): void
    {
        $this->storeZeptoMail(enabled: false);
        Http::fake([self::ENDPOINT => $this->acceptedResponse()]);
        Mail::raw('First', fn (Message $message) => $message->to('ada@example.com')->subject('One'));

        // Through the model, as the dashboard does, so the cached integration settings are cleared.
        Integration::query()->where('provider', 'zeptomail')->sole()->update(['is_enabled' => true]);
        Mail::raw('Second', fn (Message $message) => $message->to('ada@example.com')->subject('Two'));

        $this->assertSame(1, $this->fallbackMessageCount());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['subject'] === 'Two');
    }

    public function test_zeptomail_error_is_raised_with_its_reason_and_code(): void
    {
        $this->storeZeptoMail(enabled: true);
        Http::fake([self::ENDPOINT => Http::response(['data' => ['error_code' => 'TM_3201', 'message' => 'Invalid API Token'], 'message' => 'error'], 400)]);

        $this->expectException(ZeptoMailException::class);
        $this->expectExceptionMessage('ZeptoMail rejected the email: Invalid API Token (TM_3201)');

        Mail::raw('Hello', fn (Message $message) => $message->to('ada@example.com')->subject('Hi'));
    }

    public function test_connection_test_sends_a_test_email_to_the_from_address(): void
    {
        $this->storeZeptoMail(enabled: false);
        Http::fake([self::ENDPOINT => $this->acceptedResponse()]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'zeptomail'));

        $response->assertSessionHas('status', 'ZeptoMail accepted a test email to noreply@example.com.');
        Http::assertSent(fn (Request $request) => $request['to'][0]['email_address']['address'] === 'noreply@example.com');
        $this->assertTrue(Integration::query()->where('provider', 'zeptomail')->value('last_test_succeeded'));
    }

    public function test_connection_test_reports_zeptomails_reason_for_failure(): void
    {
        $this->storeZeptoMail(enabled: false);
        Http::fake([self::ENDPOINT => Http::response(['data' => ['error_code' => 'TM_3201', 'message' => 'Invalid API Token'], 'message' => 'error'], 400)]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'zeptomail'));

        $response->assertSessionHasErrors(['zeptomail.test' => 'ZeptoMail rejected the email: Invalid API Token (TM_3201)']);
    }

    public function test_api_host_outside_zoho_is_rejected(): void
    {
        $response = $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'zeptomail'), [
            'is_enabled' => false,
            'config' => ['api_host' => 'collector.attacker.example'],
        ]);

        $response->assertSessionHasErrors('config.api_host');
        $this->assertDatabaseCount('integrations', 0);
    }

    private function storeZeptoMail(bool $enabled): void
    {
        $integration = new Integration(['provider' => 'zeptomail', 'type' => 'mail', 'is_enabled' => $enabled]);
        $integration->config = [
            'api_token' => 'zepto-token',
            'api_host' => 'cpaas.zoho.com',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Ìpìlẹ̀',
        ];
        $integration->save();
    }

    private function acceptedResponse(): PromiseInterface
    {
        return Http::response(['data' => [['code' => 'EM_104', 'additional_info' => [], 'message' => 'OK']], 'message' => 'OK', 'request_id' => 'req-123']);
    }

    private function fallbackMessageCount(): int
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
