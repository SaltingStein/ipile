<?php

namespace Tests\Feature\Api\V1;

use App\Models\Integration;
use App\Models\Payment;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private const INITIALIZE_URL = 'https://api.paystack.co/transaction/initialize';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_returns_401_when_creating_a_payment_without_a_token(): void
    {
        $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'paystack'])->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_returns_401_when_viewing_a_payment_without_a_token(): void
    {
        $payment = Payment::factory()->create();

        $this->getJson(route('api.v1.payments.show', $payment->reference))->assertUnauthorized();
    }

    public function test_the_old_unauthenticated_payment_routes_no_longer_exist(): void
    {
        $payment = Payment::factory()->create();

        $this->postJson('/api/payments', ['amount' => 5000, 'provider' => 'squad'])->assertNotFound();
        $this->getJson("/api/payments/{$payment->reference}/verify")->assertNotFound();
    }

    public function test_returns_403_for_an_unverified_email(): void
    {
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'paystack'])->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_returns_403_for_a_suspended_account(): void
    {
        Sanctum::actingAs(User::factory()->suspended()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'paystack'])->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paystack_payment_is_recorded_pending_and_returns_the_checkout_url(): void
    {
        $this->enablePaystack();
        Http::fake([self::INITIALIZE_URL => $this->initializeResponse()]);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'paystack', 'payment_method' => 'card']);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '5000.00');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.checkout_url', 'https://checkout.paystack.com/abc123');
        $response->assertJsonMissingPath('data.gateway_response');
        $reference = $response->json('data.reference');
        $this->assertMatchesRegularExpression('/^pay-[a-z0-9]+$/', $reference);
        $this->assertDatabaseHas('payments', ['reference' => $reference, 'user_id' => $user->id, 'provider' => 'paystack', 'status' => 'pending']);
        Http::assertSent(fn (Request $request) => $request->url() === self::INITIALIZE_URL
            && $request->header('Authorization') === ['Bearer sk_test_secret']
            && $request['email'] === 'ada@example.com'
            && $request['amount'] === 500000
            && $request['currency'] === 'NGN'
            && $request['reference'] === $reference);
    }

    public function test_client_cannot_choose_the_owner_reference_or_status(): void
    {
        $this->enablePaystack();
        Http::fake([self::INITIALIZE_URL => $this->initializeResponse()]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.payments.store'), [
            'amount' => 5000,
            'provider' => 'paystack',
            'user_id' => $otherUser->id,
            'reference' => 'chosen-by-client',
            'status' => 'successful',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('payments', ['reference' => 'chosen-by-client']);
        $this->assertDatabaseMissing('payments', ['user_id' => $otherUser->id]);
    }

    public function test_paystack_refusal_returns_502_and_marks_the_payment_failed(): void
    {
        $this->enablePaystack();
        Http::fake([self::INITIALIZE_URL => Http::response(['status' => false, 'message' => 'Invalid amount'], 400)]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'paystack']);

        $response->assertStatus(502);
        $response->assertExactJson(['message' => 'Paystack: Invalid amount.']);
        $this->assertDatabaseHas('payments', ['provider' => 'paystack', 'status' => 'failed']);
    }

    public function test_disabled_or_unimplemented_providers_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'paystack'])
            ->assertJsonValidationErrors(['provider' => 'The selected provider is invalid.']);
        $this->postJson(route('api.v1.payments.store'), ['amount' => 5000, 'provider' => 'moniepoint'])
            ->assertJsonValidationErrors(['provider' => 'The selected provider is invalid.']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_zero_amount_returns_422(): void
    {
        $this->enablePaystack();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.store'), ['amount' => 0, 'provider' => 'paystack'])
            ->assertJsonValidationErrors(['amount' => 'The amount field must be at least 0.01.']);
    }

    public function test_index_lists_only_the_users_payments_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Payment::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $newer = Payment::factory()->for($user)->create(['created_at' => now()]);
        Payment::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.payments.index'));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.reference', $newer->reference);
        $response->assertJsonPath('data.1.reference', $older->reference);
    }

    public function test_show_returns_the_users_payment(): void
    {
        $payment = Payment::factory()->create(['status' => 'successful']);
        Sanctum::actingAs($payment->user);

        $this->getJson(route('api.v1.payments.show', $payment->reference))
            ->assertOk()
            ->assertJsonPath('data.reference', $payment->reference)
            ->assertJsonPath('data.status', 'successful');
    }

    public function test_show_returns_404_for_another_users_payment(): void
    {
        $payment = Payment::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.payments.show', $payment->reference))->assertNotFound();
    }

    public function test_verify_marks_a_paid_paystack_payment_successful(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 5000, 'currency' => 'NGN']);
        Http::fake(["https://api.paystack.co/transaction/verify/{$payment->reference}" => $this->verifyResponse($payment->reference, 'success', 500000)]);
        Sanctum::actingAs($payment->user);

        $response = $this->postJson(route('api.v1.payments.verify', $payment->reference));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'successful');
        $response->assertJsonPath('data.checkout_url', null);
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_verify_leaves_an_abandoned_payment_pending(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 5000]);
        Http::fake(["https://api.paystack.co/transaction/verify/{$payment->reference}" => $this->verifyResponse($payment->reference, 'abandoned', 500000)]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'pending');
    }

    public function test_verify_does_not_accept_a_lower_amount_than_the_ledger(): void
    {
        $this->enablePaystack();
        $payment = Payment::factory()->create(['provider' => 'paystack', 'amount' => 5000]);
        Http::fake(["https://api.paystack.co/transaction/verify/{$payment->reference}" => $this->verifyResponse($payment->reference, 'success', 100)]);
        Sanctum::actingAs($payment->user);

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_verify_returns_404_for_another_users_payment_without_calling_paystack(): void
    {
        $this->enablePaystack();
        Http::fake();
        $payment = Payment::factory()->create(['provider' => 'paystack']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.payments.verify', $payment->reference))->assertNotFound();

        Http::assertNothingSent();
    }

    private function enablePaystack(): void
    {
        $integration = new Integration(['provider' => 'paystack', 'type' => 'payment', 'is_enabled' => true]);
        $integration->config = ['public_key' => 'pk_test_public', 'secret_key' => 'sk_test_secret'];
        $integration->save();
    }

    private function initializeResponse(): PromiseInterface
    {
        return Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => 'ignored'],
        ]);
    }

    private function verifyResponse(string $reference, string $status, int $amountInKobo): PromiseInterface
    {
        return Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => ['status' => $status, 'reference' => $reference, 'amount' => $amountInKobo, 'currency' => 'NGN'],
        ]);
    }
}
