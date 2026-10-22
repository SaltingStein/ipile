<?php

namespace Tests\Feature\Services\Payments\Paystack;

use App\Models\Integration;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paystack webhooks through POST /api/webhooks/paystack, and the Paystack connection test.
 */
class PaystackGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'sk_test_secret';

    public function test_signed_charge_success_marks_the_payment_successful(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 100, 'currency' => 'NGN']);

        $response = $this->sendWebhook($this->chargeSuccess($payment->reference, 10000));

        $response->assertOk();
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_signature_made_with_another_key_is_rejected(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 100]);

        $response = $this->sendWebhook($this->chargeSuccess($payment->reference, 10000), signingKey: 'sk_attacker');

        $response->assertUnauthorized();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_body_changed_after_signing_is_rejected(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 100]);
        $signedBody = $this->chargeSuccess($payment->reference, 1);

        $response = $this->sendWebhook($this->chargeSuccess($payment->reference, 10000), signature: hash_hmac('sha512', $signedBody, self::SECRET_KEY));

        $response->assertUnauthorized();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhooks_are_rejected_while_paystack_is_disabled(): void
    {
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 100]);

        $this->sendWebhook($this->chargeSuccess($payment->reference, 10000))->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_other_signed_events_are_acknowledged_without_changes(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 100]);
        $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => $payment->reference, 'amount' => 10000, 'currency' => 'NGN', 'status' => 'success']]);

        $this->sendWebhook($body)->assertOk()->assertJson(['message' => 'Event ignored.']);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_connection_test_passes_with_a_valid_secret_key(): void
    {
        $this->enablePaystack();
        Http::preventStrayRequests();
        Http::fake(['https://api.paystack.co/balance' => Http::response(['status' => true, 'message' => 'Balances retrieved', 'data' => []])]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'paystack'));

        $response->assertSessionHas('status', 'Paystack accepted the secret key.');
        Http::assertSent(fn ($request) => $request->header('Authorization') === ['Bearer '.self::SECRET_KEY]);
    }

    public function test_connection_test_reports_a_rejected_secret_key(): void
    {
        $this->enablePaystack();
        Http::preventStrayRequests();
        Http::fake(['https://api.paystack.co/balance' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'paystack'));

        $response->assertSessionHasErrors(['paystack.test' => 'Paystack rejected the secret key.']);
    }

    private function sendWebhook(string $body, ?string $signingKey = self::SECRET_KEY, ?string $signature = null): TestResponse
    {
        return $this->call('POST', route('webhooks.payment', 'paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, $signingKey),
        ], $body);
    }

    private function chargeSuccess(string $reference, int $amountInKobo): string
    {
        return json_encode([
            'event' => 'charge.success',
            'data' => ['status' => 'success', 'reference' => $reference, 'amount' => $amountInKobo, 'currency' => 'NGN'],
        ]);
    }

    private function enablePaystack(): void
    {
        $integration = new Integration(['provider' => 'paystack', 'type' => 'payment', 'is_enabled' => true]);
        $integration->config = ['public_key' => 'pk_test_public', 'secret_key' => self::SECRET_KEY];
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
