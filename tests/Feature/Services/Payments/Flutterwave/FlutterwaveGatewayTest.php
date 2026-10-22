<?php

namespace Tests\Feature\Services\Payments\Flutterwave;

use App\Models\Integration;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flutterwave through the payment API, its webhook (POST /api/webhooks/flutterwave) and the connection test.
 * Webhook payloads follow the samples on developer.flutterwave.com/docs/webhooks.
 */
class FlutterwaveGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_HASH = 'a-long-random-secret-hash';

    private const API = 'https://api.flutterwave.com/v3';

    public function test_payment_api_creates_a_flutterwave_payment_link_in_naira(): void
    {
        $this->enableFlutterwave();
        Http::fake([self::API.'/payments' => Http::response([
            'status' => 'success',
            'message' => 'Hosted Link',
            'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/flwlnk-123'],
        ])]);
        $user = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.payments.store'), ['amount' => 7500, 'provider' => 'flutterwave']);

        $response->assertCreated();
        $response->assertJsonPath('data.checkout_url', 'https://checkout.flutterwave.com/v3/hosted/pay/flwlnk-123');
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer FLWSECK_TEST-secret']
            && $request['tx_ref'] === $response->json('data.reference')
            && $request['amount'] === '7500.00'
            && $request['currency'] === 'NGN'
            && $request['redirect_url'] === 'https://shop.example.com/paid'
            && $request['customer'] === ['email' => 'ada@example.com', 'name' => 'Ada Obi']);
    }

    public function test_flutterwave_error_returns_502_with_its_message(): void
    {
        $this->enableFlutterwave();
        Http::fake([self::API.'/payments' => Http::response(['status' => 'error', 'message' => 'Invalid currency', 'data' => null], 400)]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 7500, 'provider' => 'flutterwave'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Flutterwave: Invalid currency.']);
    }

    public function test_verify_by_reference_applies_a_successful_transaction(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00', 'currency' => 'NGN']);
        Http::fake([self::API.'/transactions/verify_by_reference*' => Http::response([
            'status' => 'success',
            'message' => 'Transaction fetched successfully',
            'data' => ['tx_ref' => $payment->reference, 'status' => 'successful', 'amount' => 100, 'currency' => 'NGN'],
        ])]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'successful');

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/transactions/verify_by_reference?tx_ref='.$payment->reference);
    }

    public function test_verify_leaves_a_pending_transaction_pending(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00']);
        Http::fake([self::API.'/transactions/verify_by_reference*' => Http::response([
            'status' => 'success',
            'data' => ['tx_ref' => $payment->reference, 'status' => 'pending', 'amount' => 100, 'currency' => 'NGN'],
        ])]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'pending');
    }

    public function test_successful_charge_webhook_with_the_secret_hash_marks_the_payment_successful(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00', 'currency' => 'NGN']);

        $this->sendWebhook($this->chargeCompleted($payment->reference, 'successful', 100))->assertOk();

        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_failed_charge_webhook_marks_the_payment_failed(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '1000.00', 'currency' => 'NGN']);

        $this->sendWebhook($this->chargeCompleted($payment->reference, 'failed', 1000))->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_webhook_with_a_wrong_secret_hash_is_rejected(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00']);

        $this->sendWebhook($this->chargeCompleted($payment->reference, 'successful', 100), secretHash: 'guessed-value')->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_without_the_header_is_rejected(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00']);

        $this->sendWebhook($this->chargeCompleted($payment->reference, 'successful', 100), secretHash: null)->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhooks_are_rejected_while_flutterwave_is_disabled(): void
    {
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00']);

        $this->sendWebhook($this->chargeCompleted($payment->reference, 'successful', 100))->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_underpaid_webhook_does_not_mark_the_payment_paid(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00', 'currency' => 'NGN']);

        $this->sendWebhook($this->chargeCompleted($payment->reference, 'successful', 1))->assertUnprocessable();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_transfer_events_are_acknowledged_without_changes(): void
    {
        $this->enableFlutterwave();
        $payment = Payment::factory()->create(['provider' => 'flutterwave', 'amount' => '100.00']);
        $body = json_encode(['event' => 'transfer.completed', 'event.type' => 'Transfer', 'data' => ['reference' => $payment->reference, 'status' => 'SUCCESSFUL', 'amount' => 100, 'currency' => 'NGN']]);

        $this->sendWebhook($body)->assertOk()->assertJson(['message' => 'Event ignored.']);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_connection_test_passes_with_a_valid_secret_key(): void
    {
        $this->enableFlutterwave();
        Http::fake([self::API.'/balances' => Http::response(['status' => 'success', 'message' => 'Wallet balances fetched', 'data' => []])]);

        $this->actingAs($this->integrationsManager())
            ->post(route('admin.integrations.test', 'flutterwave'))
            ->assertSessionHas('status', 'Flutterwave accepted the secret key.');
    }

    public function test_connection_test_reports_a_rejected_secret_key(): void
    {
        $this->enableFlutterwave();
        Http::fake([self::API.'/balances' => Http::response(['status' => 'error', 'message' => 'Invalid authorization key', 'data' => null], 401)]);

        $this->actingAs($this->integrationsManager())
            ->post(route('admin.integrations.test', 'flutterwave'))
            ->assertSessionHasErrors(['flutterwave.test' => 'Flutterwave rejected the secret key.']);
    }

    private function sendWebhook(string $body, ?string $secretHash = self::SECRET_HASH): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secretHash !== null) {
            $server['HTTP_VERIF_HASH'] = $secretHash;
        }

        return $this->call('POST', route('webhooks.payment', 'flutterwave'), [], [], [], $server, $body);
    }

    private function chargeCompleted(string $txRef, string $status, int $amountInNaira): string
    {
        return json_encode([
            'event' => 'charge.completed',
            'data' => [
                'id' => 285959875,
                'tx_ref' => $txRef,
                'flw_ref' => 'FLW270177170',
                'amount' => $amountInNaira,
                'currency' => 'NGN',
                'charged_amount' => $amountInNaira,
                'status' => $status,
                'payment_type' => 'card',
            ],
        ]);
    }

    private function enableFlutterwave(): void
    {
        $integration = new Integration(['provider' => 'flutterwave', 'type' => 'payment', 'is_enabled' => true]);
        $integration->config = [
            'secret_key' => 'FLWSECK_TEST-secret',
            'webhook_secret_hash' => self::SECRET_HASH,
            'redirect_url' => 'https://shop.example.com/paid',
        ];
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
