<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Payments\BasePaymentGateway;
use App\Services\Payments\MoniepointGateway;
use App\Services\Payments\PaymentWebhook;
use App\Services\Payments\Squad\SquadGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_SECRET = 'test-webhook-secret';

    public function test_unsigned_webhook_cannot_mark_a_payment_successful(): void
    {
        $payment = Payment::factory()->create(['provider' => 'squad']);

        $response = $this->postJson(route('webhooks.payment', 'squad'), [
            'reference' => $payment->reference,
            'status' => 'successful',
        ]);

        $response->assertUnauthorized();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_old_generic_signature_header_is_not_accepted(): void
    {
        $payment = Payment::factory()->create(['provider' => 'flutterwave']);
        $payload = ['data' => ['reference' => $payment->reference]];

        $response = $this->postJson(route('webhooks.payment', 'flutterwave'), $payload, [
            'X-Signature' => hash_hmac('sha256', json_encode($payload), ''),
        ]);

        $response->assertUnauthorized();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_unsupported_provider_returns_404(): void
    {
        $this->postJson(route('webhooks.payment', 'paypal'), [])->assertNotFound();
    }

    public function test_signed_success_webhook_marks_the_payment_successful_and_keeps_the_payload(): void
    {
        $this->useSignedTestGateway();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000]);

        $response = $this->sendSignedWebhook($payment->reference, 'successful', '5000.00');

        $response->assertOk();
        $response->assertJson(['message' => 'Webhook processed.']);
        $payment->refresh();
        $this->assertSame('successful', $payment->status);
        $this->assertSame($payment->reference, $payment->gateway_response['webhook']['reference']);
    }

    public function test_signed_failure_webhook_marks_the_payment_failed(): void
    {
        $this->useSignedTestGateway();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000]);

        $this->sendSignedWebhook($payment->reference, 'failed', '5000')->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_repeated_webhook_does_not_change_a_final_status(): void
    {
        $this->useSignedTestGateway();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000]);
        $this->sendSignedWebhook($payment->reference, 'successful', '5000');

        $response = $this->sendSignedWebhook($payment->reference, 'failed', '5000');

        $response->assertOk();
        $response->assertJson(['message' => 'Payment already processed.']);
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_settled_payments_notify_the_payer_once(): void
    {
        Notification::fake();
        $this->useSignedTestGateway();
        $user = User::factory()->create();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000, 'currency' => 'NGN', 'user_id' => $user->id]);

        $this->sendSignedWebhook($payment->reference, 'successful', '5000');
        $this->sendSignedWebhook($payment->reference, 'successful', '5000');

        Notification::assertSentToTimes($user, EventNotification::class, 1);
        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'payment.succeeded'
            && $notification->data === ['amount' => 'NGN 5,000.00', 'reference' => $payment->reference]);
    }

    public function test_failed_payments_notify_the_payer(): void
    {
        Notification::fake();
        $this->useSignedTestGateway();
        $user = User::factory()->create();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000, 'user_id' => $user->id]);

        $this->sendSignedWebhook($payment->reference, 'failed', '5000');

        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'payment.failed');
    }

    public function test_amount_that_differs_from_the_ledger_is_rejected(): void
    {
        $this->useSignedTestGateway();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000]);

        $response = $this->sendSignedWebhook($payment->reference, 'successful', '50.00');

        $response->assertUnprocessable();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_currency_that_differs_from_the_ledger_is_rejected(): void
    {
        $this->useSignedTestGateway();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 5000, 'currency' => 'NGN']);

        $response = $this->sendSignedWebhook($payment->reference, 'successful', '5000', 'USD');

        $response->assertUnprocessable();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_reference_belonging_to_another_provider_is_not_updated(): void
    {
        $this->useSignedTestGateway();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => 5000]);

        $response = $this->sendSignedWebhook($payment->reference, 'successful', '5000');

        $response->assertNotFound();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_unknown_reference_returns_404(): void
    {
        $this->useSignedTestGateway();

        $this->sendSignedWebhook('pay_does_not_exist', 'successful', '5000')->assertNotFound();
    }

    public function test_stub_gateway_verify_never_marks_a_payment_successful(): void
    {
        $payment = Payment::factory()->create(['provider' => 'moniepoint']);

        $verified = app(MoniepointGateway::class)->verify($payment->reference);

        $this->assertSame('pending', $verified->status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    /**
     * Stand in for a real Squad adapter: HMAC-SHA512 over the raw body with a known secret.
     */
    private function useSignedTestGateway(): void
    {
        $this->app->bind(SquadGateway::class, fn () => new class(self::TEST_SECRET) extends BasePaymentGateway
        {
            public function __construct(private string $secret) {}

            public function getProviderName(): string
            {
                return 'squad';
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                return hash_equals(hash_hmac('sha512', $request->getContent(), $this->secret), (string) $request->header('X-Test-Signature'));
            }

            public function parseWebhook(Request $request): ?PaymentWebhook
            {
                return new PaymentWebhook(
                    reference: $request->string('reference')->toString(),
                    status: $request->string('status')->toString(),
                    amount: $request->string('amount')->toString(),
                    currency: $request->string('currency')->toString(),
                    payload: $request->all(),
                );
            }
        });
    }

    private function sendSignedWebhook(string $reference, string $status, string $amount, string $currency = 'NGN'): TestResponse
    {
        $body = json_encode(compact('reference', 'status', 'amount', 'currency'));

        return $this->call('POST', route('webhooks.payment', 'squad'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TEST_SIGNATURE' => hash_hmac('sha512', $body, self::TEST_SECRET),
        ], $body);
    }
}
