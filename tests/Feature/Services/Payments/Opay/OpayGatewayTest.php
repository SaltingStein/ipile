<?php

namespace Tests\Feature\Services\Payments\Opay;

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
 * OPay through the payment API, its callback (POST /api/webhooks/opay) and the connection test.
 * The callback test vector (payload, private key, sha512) is the one published in OPay's
 * "Callback Notification Signature" documentation.
 */
class OpayGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const DOC_PRIVATE_KEY = 'OPAYPRV16498196872570.13953388019021462';

    private const DOC_CALLBACK = '{"payload":{"amount":"49160","channel":"Web","country":"NG","currency":"NGN","displayedFailure":"","fee":"737","feeCurrency":"NGN","instrumentType":"BankCard","reference":"10023","refunded":false,"status":"SUCCESS","timestamp":"2022-05-07T06:20:46Z","token":"220507145660712931829","transactionId":"220507145660712931829","updated_at":"2022-05-07T07:20:46Z"},"sha512":"9f605d69f04e94172875dc156537071cead060bbcaeaca94a7b8805af9f89611e2fdf6836713c9c90b028ca7e4470b1356e996975f2abc862315aaa9b7f2ae2d","type":"transaction-status"}';

    private const SANDBOX = 'https://testapi.opaycheckout.com';

    public function test_opays_documented_callback_marks_the_payment_successful(): void
    {
        $this->enableOpay(secretKey: self::DOC_PRIVATE_KEY);
        $payment = Payment::factory()->create(['provider' => 'opay', 'reference' => '10023', 'amount' => '491.60', 'currency' => 'NGN']);

        $this->sendCallback(self::DOC_CALLBACK)->assertOk();

        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_callback_with_a_changed_amount_is_rejected(): void
    {
        $this->enableOpay(secretKey: self::DOC_PRIVATE_KEY);
        $payment = Payment::factory()->create(['provider' => 'opay', 'reference' => '10023', 'amount' => '491.60']);

        $this->sendCallback(str_replace('"amount":"49160"', '"amount":"99160"', self::DOC_CALLBACK))->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_callback_signed_with_another_key_is_rejected(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-a-different-merchant');
        $payment = Payment::factory()->create(['provider' => 'opay', 'reference' => '10023', 'amount' => '491.60']);

        $this->sendCallback(self::DOC_CALLBACK)->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_callbacks_are_rejected_while_opay_is_disabled(): void
    {
        $payment = Payment::factory()->create(['provider' => 'opay', 'reference' => '10023', 'amount' => '491.60']);

        $this->sendCallback(self::DOC_CALLBACK)->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_signed_failed_callback_marks_the_payment_failed(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        $payment = Payment::factory()->create(['provider' => 'opay', 'amount' => '100.00']);

        $this->sendCallback($this->signedCallback($payment->reference, '10000', 'FAIL', 'OPAYPRV-test'))->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_refund_callbacks_are_acknowledged_without_changes(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        $payment = Payment::factory()->create(['provider' => 'opay', 'amount' => '100.00']);

        $this->sendCallback($this->signedCallback($payment->reference, '10000', 'SUCCESS', 'OPAYPRV-test', refunded: true))
            ->assertOk()
            ->assertJson(['message' => 'Event ignored.']);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_payment_api_opens_an_opay_cashier(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        Http::fake([self::SANDBOX.'/api/v1/international/cashier/create' => Http::response([
            'code' => '00000',
            'message' => 'SUCCESSFUL',
            'data' => ['reference' => 'ignored', 'orderNo' => '211009140896553163', 'cashierUrl' => 'https://sandboxcashier.opaycheckout.com/checkout?orderToken=T1', 'status' => 'INITIAL'],
        ])]);
        $user = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.payments.store'), ['amount' => 4, 'provider' => 'opay']);

        $response->assertCreated();
        $response->assertJsonPath('data.checkout_url', 'https://sandboxcashier.opaycheckout.com/checkout?orderToken=T1');
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer OPAYPUB-test']
            && $request->header('MerchantId') === ['256612345678901']
            && $request['country'] === 'NG'
            && $request['reference'] === $response->json('data.reference')
            && $request['amount'] === ['total' => 400, 'currency' => 'NGN']
            && $request['returnUrl'] === 'https://shop.example.com/paid'
            && $request['callbackUrl'] === route('webhooks.payment', 'opay')
            && $request['userInfo']['userEmail'] === 'ada@example.com');
    }

    public function test_opay_error_returns_502_with_its_message_and_code(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        Http::fake([self::SANDBOX.'/api/v1/international/cashier/create' => Http::response(['code' => '02001', 'message' => 'request params not valid.'])]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 4, 'provider' => 'opay'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'OPay: request params not valid (02001).']);
    }

    public function test_verify_signs_the_status_query_and_applies_success(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        $payment = Payment::factory()->create(['provider' => 'opay', 'amount' => '4.00', 'currency' => 'NGN']);
        Http::fake([self::SANDBOX.'/api/v1/international/cashier/status' => Http::response([
            'code' => '00000',
            'message' => 'SUCCESSFUL',
            'data' => ['reference' => $payment->reference, 'status' => 'SUCCESS', 'amount' => ['total' => 400, 'currency' => 'NGN']],
        ])]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'successful');

        Http::assertSent(fn (Request $request) => $request->body() === json_encode(['country' => 'NG', 'reference' => $payment->reference])
            && $request->header('Authorization') === ['Bearer '.hash_hmac('sha512', $request->body(), 'OPAYPRV-test')]);
    }

    public function test_verify_marks_a_closed_order_failed(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        $payment = Payment::factory()->create(['provider' => 'opay', 'amount' => '4.00', 'currency' => 'NGN']);
        Http::fake([self::SANDBOX.'/api/v1/international/cashier/status' => Http::response([
            'code' => '00000',
            'data' => ['reference' => $payment->reference, 'status' => 'CLOSE', 'amount' => ['total' => 400, 'currency' => 'NGN']],
        ])]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'failed');
    }

    public function test_connection_test_passes_when_opay_reports_payment_not_found(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        Http::fake([self::SANDBOX.'/api/v1/international/cashier/status' => Http::response(['code' => '02006', 'message' => 'payment not found.'])]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'opay'));

        $response->assertSessionHas('status', 'OPay accepted the merchant ID and secret key (sandbox).');
    }

    public function test_connection_test_reports_rejected_credentials(): void
    {
        $this->enableOpay(secretKey: 'OPAYPRV-test');
        Http::fake([self::SANDBOX.'/api/v1/international/cashier/status' => Http::response(['code' => '02000', 'message' => 'authentication failed.'])]);

        $response = $this->actingAs($this->integrationsManager())->post(route('admin.integrations.test', 'opay'));

        $response->assertSessionHasErrors(['opay.test' => 'OPay rejected the merchant ID or secret key.']);
    }

    private function sendCallback(string $body): TestResponse
    {
        return $this->call('POST', route('webhooks.payment', 'opay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function signedCallback(string $reference, string $amountInKobo, string $status, string $secretKey, bool $refunded = false): string
    {
        $payload = [
            'amount' => $amountInKobo,
            'currency' => 'NGN',
            'reference' => $reference,
            'refunded' => $refunded,
            'status' => $status,
            'timestamp' => '2026-10-14T10:00:00Z',
            'token' => 'T123',
            'transactionId' => 'T123',
        ];
        $signedContent = sprintf(
            '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
            $payload['amount'], $payload['currency'], $payload['reference'], $refunded ? 't' : 'f', $payload['status'], $payload['timestamp'], $payload['token'], $payload['transactionId'],
        );

        return json_encode(['payload' => $payload, 'sha512' => hash_hmac('sha3-512', $signedContent, $secretKey), 'type' => 'transaction-status']);
    }

    private function enableOpay(string $secretKey): void
    {
        $integration = new Integration(['provider' => 'opay', 'type' => 'payment', 'is_enabled' => true]);
        $integration->config = [
            'merchant_id' => '256612345678901',
            'public_key' => 'OPAYPUB-test',
            'secret_key' => $secretKey,
            'environment' => 'sandbox',
            'return_url' => 'https://shop.example.com/paid',
        ];
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
