<?php

namespace Tests\Feature\Services\Payments\Squad;

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
 * Squad through the payment API, its webhook (POST /api/webhooks/squad) and the connection test.
 * Payloads follow the samples on docs.squadco.com.
 */
class SquadGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'sandbox_sk_test_secret';

    private const SANDBOX = 'https://sandbox-api-d.squadco.com';

    public function test_payment_api_opens_a_squad_checkout_in_the_sandbox(): void
    {
        $this->enableSquad();
        Http::fake([self::SANDBOX.'/transaction/initiate' => Http::response([
            'status' => 200,
            'message' => 'success',
            'data' => ['transaction_ref' => 'ignored', 'checkout_url' => 'https://sandbox-pay.squadco.com/ref123'],
        ])]);
        $user = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.payments.store'), ['amount' => 430, 'provider' => 'squad']);

        $response->assertCreated();
        $response->assertJsonPath('data.checkout_url', 'https://sandbox-pay.squadco.com/ref123');
        $reference = $response->json('data.reference');
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer '.self::SECRET_KEY]
            && $request['amount'] === 43000
            && $request['email'] === 'ada@example.com'
            && $request['customer_name'] === 'Ada Obi'
            && $request['currency'] === 'NGN'
            && $request['initiate_type'] === 'inline'
            && $request['transaction_ref'] === $reference);
    }

    public function test_live_environment_uses_the_live_api(): void
    {
        $this->enableSquad(environment: 'live');
        Http::fake(['https://api-d.squadco.com/transaction/initiate' => Http::response([
            'status' => 200,
            'message' => 'success',
            'data' => ['checkout_url' => 'https://pay.squadco.com/ref123'],
        ])]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 430, 'provider' => 'squad'])->assertCreated();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api-d.squadco.com/'));
    }

    public function test_squad_error_returns_502_with_its_message(): void
    {
        $this->enableSquad();
        Http::fake([self::SANDBOX.'/transaction/initiate' => Http::response(['status' => 400, 'success' => false, 'message' => '"email" is required', 'data' => []], 400)]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 430, 'provider' => 'squad'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Squad: "email" is required.']);
    }

    public function test_verify_applies_a_successful_transaction(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 50, 'currency' => 'NGN']);
        Http::fake([self::SANDBOX."/transaction/verify/{$payment->reference}" => Http::response([
            'status' => 200,
            'success' => true,
            'message' => 'Success',
            'data' => ['transaction_amount' => 5000, 'transaction_ref' => $payment->reference, 'transaction_status' => 'Success', 'transaction_currency_id' => 'NGN'],
        ])]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'successful');
    }

    public function test_verify_leaves_an_abandoned_transaction_pending(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 50]);
        Http::fake([self::SANDBOX."/transaction/verify/{$payment->reference}" => Http::response([
            'status' => 200,
            'success' => true,
            'data' => ['transaction_amount' => 5000, 'transaction_ref' => $payment->reference, 'transaction_status' => 'Abandoned', 'transaction_currency_id' => 'NGN'],
        ])]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'pending');
    }

    public function test_signed_charge_successful_webhook_marks_the_payment_successful(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 100, 'currency' => 'NGN']);

        $this->sendWebhook($this->chargeSuccessful($payment->reference, 10000))->assertOk();

        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_lowercase_signature_is_accepted(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 100]);
        $body = $this->chargeSuccessful($payment->reference, 10000);

        $this->sendWebhook($body, signature: hash_hmac('sha512', $body, self::SECRET_KEY))->assertOk();

        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_signature_made_with_another_key_is_rejected(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 100]);
        $body = $this->chargeSuccessful($payment->reference, 10000);

        $this->sendWebhook($body, signature: strtoupper(hash_hmac('sha512', $body, 'sandbox_sk_attacker')))->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_body_changed_after_signing_is_rejected(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 100]);
        $signedBody = $this->chargeSuccessful($payment->reference, 1);

        $this->sendWebhook($this->chargeSuccessful($payment->reference, 10000), signature: strtoupper(hash_hmac('sha512', $signedBody, self::SECRET_KEY)))
            ->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhooks_are_rejected_while_squad_is_disabled(): void
    {
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 100]);

        $this->sendWebhook($this->chargeSuccessful($payment->reference, 10000))->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_other_events_are_acknowledged_without_changes(): void
    {
        $this->enableSquad();
        $payment = Payment::factory()->create(['provider' => 'squad', 'amount' => 100]);
        $body = json_encode(['Event' => 'charge_refunded', 'TransactionRef' => $payment->reference, 'Body' => ['amount' => 10000, 'transaction_ref' => $payment->reference, 'transaction_status' => 'Success', 'currency' => 'NGN']]);

        $this->sendWebhook($body)->assertOk()->assertJson(['message' => 'Event ignored.']);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_connection_test_passes_when_squad_accepts_the_key(): void
    {
        $this->enableSquad();
        Http::fake([self::SANDBOX.'/transaction/verify/ipile-connection-check' => Http::response(['status' => 400, 'success' => false, 'message' => 'Invalid transaction reference', 'data' => []], 400)]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'squad'));

        $response->assertSessionHas('status', 'Squad accepted the secret key (sandbox).');
    }

    public function test_connection_test_reports_a_rejected_key(): void
    {
        $this->enableSquad();
        Http::fake([self::SANDBOX.'/transaction/verify/ipile-connection-check' => Http::response(['success' => false, 'message' => 'API key is invalid. Key must start with sandbox_sk_', 'data' => []], 403)]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'squad'));

        $response->assertSessionHasErrors(['squad.test' => 'Squad rejected the secret key: API key is invalid. Key must start with sandbox_sk_.']);
    }

    private function sendWebhook(string $body, ?string $signature = null): TestResponse
    {
        return $this->call('POST', route('webhooks.payment', 'squad'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SQUAD_ENCRYPTED_BODY' => $signature ?? strtoupper(hash_hmac('sha512', $body, self::SECRET_KEY)),
        ], $body);
    }

    private function chargeSuccessful(string $reference, int $amountInKobo): string
    {
        return json_encode([
            'Event' => 'charge_successful',
            'TransactionRef' => $reference,
            'Body' => [
                'amount' => $amountInKobo,
                'transaction_ref' => $reference,
                'transaction_status' => 'Success',
                'currency' => 'NGN',
                'transaction_type' => 'Card',
            ],
        ]);
    }

    private function enableSquad(string $environment = 'sandbox'): void
    {
        $integration = new Integration(['provider' => 'squad', 'type' => 'payment', 'is_enabled' => true]);
        $integration->config = ['secret_key' => self::SECRET_KEY, 'environment' => $environment];
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
