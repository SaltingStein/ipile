<?php

namespace Tests\Feature\Services\Mail;

use App\Models\Integration;
use App\Models\User;
use App\Services\Mail\ResendClient;
use App\Services\Mail\ResendException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers ResendTransport, ResendClient and ResendTester through the `dashboard` mailer and Admin → Integrations.
 */
class ResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'dashboard', 'mail.mailers.dashboard.fallback' => 'array']);
        Http::preventStrayRequests();
    }

    public function test_enabled_resend_receives_the_email_with_the_dashboard_sender(): void
    {
        $this->storeResend(enabled: true);
        Http::fake([ResendClient::ENDPOINT => Http::response(['id' => 'email-123'])]);

        Mail::raw('Your order has shipped.', function (Message $message): void {
            $message->to('ada@example.com', 'Ada Obi')->cc('ops@example.com')->subject('Order update')->replyTo('support@example.com', 'Support, Lagos');
        });

        Http::assertSent(fn (Request $request) => $request->url() === ResendClient::ENDPOINT
            && $request->header('Authorization') === ['Bearer re_test_key']
            && $request['from'] === 'Ìpìlẹ̀ <noreply@example.com>'
            && $request['to'] === ['Ada Obi <ada@example.com>']
            && $request['cc'] === ['ops@example.com']
            && $request['reply_to'] === ['"Support, Lagos" <support@example.com>']
            && $request['subject'] === 'Order update'
            && $request['text'] === 'Your order has shipped.');
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());
    }

    public function test_attachments_are_sent_base64_encoded(): void
    {
        $this->storeResend(enabled: true);
        Http::fake([ResendClient::ENDPOINT => Http::response(['id' => 'email-123'])]);

        Mail::raw('Invoice attached.', function (Message $message): void {
            $message->to('ada@example.com')->subject('Invoice')->attachData('invoice-body', 'invoice.txt', ['mime' => 'text/plain']);
        });

        Http::assertSent(fn (Request $request) => $request['attachments'] === [[
            'filename' => 'invoice.txt',
            'content' => base64_encode('invoice-body'),
            'content_type' => 'text/plain',
        ]]);
    }

    public function test_resend_error_is_raised_with_its_reason_and_name(): void
    {
        $this->storeResend(enabled: true);
        Http::fake([ResendClient::ENDPOINT => Http::response(['statusCode' => 403, 'name' => 'validation_error', 'message' => 'The example.com domain is not verified.'], 403)]);

        $this->expectException(ResendException::class);
        $this->expectExceptionMessage('Resend rejected the email: The example.com domain is not verified. (validation_error)');

        Mail::raw('Hello', fn (Message $message) => $message->to('ada@example.com')->subject('Hi'));
    }

    public function test_connection_test_sends_a_test_email_to_the_from_address(): void
    {
        $this->storeResend(enabled: false);
        Http::fake([ResendClient::ENDPOINT => Http::response(['id' => 'email-123'])]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'resend'));

        $response->assertSessionHas('status', 'Resend accepted a test email to noreply@example.com.');
        Http::assertSent(fn (Request $request) => $request['to'] === ['Ìpìlẹ̀ <noreply@example.com>']);
    }

    public function test_an_api_key_in_the_wrong_format_is_rejected(): void
    {
        $response = $this->actingAs($this->integrationsManager())->put(route('admin.integrations.update', 'resend'), [
            'is_enabled' => false,
            'config' => ['api_key' => 'sk_live_not_resend'],
        ]);

        $response->assertSessionHasErrors('config.api_key');
        $this->assertDatabaseCount('integrations', 0);
    }

    private function storeResend(bool $enabled): void
    {
        $integration = new Integration(['provider' => 'resend', 'type' => 'mail', 'is_enabled' => $enabled]);
        $integration->config = ['api_key' => 're_test_key', 'from_address' => 'noreply@example.com', 'from_name' => 'Ìpìlẹ̀'];
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
