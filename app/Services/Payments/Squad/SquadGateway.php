<?php

namespace App\Services\Payments\Squad;

use App\Models\Payment;
use App\Services\Integrations\IntegrationManager;
use App\Services\Payments\BasePaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentWebhook;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Http\Request;

/**
 * Squad (GTCO) checkout: the payer is sent to Squad's hosted page (`checkout_url`), and the payment is
 * confirmed by the signed `charge_successful` webhook or by calling verify.
 * Credentials and environment (sandbox/live) come from Admin → Integrations.
 */
class SquadGateway extends BasePaymentGateway
{
    public function __construct(
        private IntegrationManager $integrations,
        private SquadClient $client,
        private PaymentWebhookProcessor $processor,
    ) {}

    public function getProviderName(): string
    {
        return 'squad';
    }

    public function isAvailable(): bool
    {
        return $this->integrations->isEnabled('squad');
    }

    /**
     * Record a pending payment and open a Squad checkout for it.
     *
     * @param  array{user_id: int, email: string, name?: string, amount: string|int|float, currency: string, reference: string, payment_method?: ?string}  $payload
     *
     * @throws PaymentGatewayException
     */
    public function charge(array $payload): Payment
    {
        $config = $this->config();
        $payment = parent::charge($payload);

        try {
            $checkout = $this->client->initiate($config, array_filter([
                'email' => $payload['email'],
                'amount' => $this->toMinorUnits($payment->amount),
                'currency' => $payment->currency,
                'transaction_ref' => $payment->reference,
                'customer_name' => $payload['name'] ?? $payload['email'],
                'callback_url' => $config['callback_url'] ?? null,
                'metadata' => ['payment_id' => $payment->id],
            ]));
        } catch (PaymentGatewayException $exception) {
            $payment->update(['status' => 'failed', 'gateway_response' => [...(array) $payment->gateway_response, 'error' => $exception->getMessage()]]);

            throw $exception;
        }

        $payment->update(['gateway_response' => [
            ...(array) $payment->gateway_response,
            'checkout_url' => $checkout['checkout_url'] ?? null,
        ]]);

        return $payment;
    }

    /**
     * Ask Squad for the transaction's status and apply a final result once. Abandoned or pending
     * transactions leave the payment pending.
     *
     * @throws PaymentGatewayException
     */
    public function verify(string $reference): Payment
    {
        $payment = parent::verify($reference);
        $transaction = $this->client->verify($this->config(), $reference);

        $status = match (strtolower((string) ($transaction['transaction_status'] ?? ''))) {
            'success' => PaymentWebhook::STATUS_SUCCESSFUL,
            'failed' => PaymentWebhook::STATUS_FAILED,
            default => null,
        };

        if ($status !== null) {
            $this->processor->apply($payment, new PaymentWebhook(
                reference: $reference,
                status: $status,
                amount: $this->fromMinorUnits((int) ($transaction['transaction_amount'] ?? 0)),
                currency: (string) ($transaction['transaction_currency_id'] ?? ''),
                payload: ['verify' => $transaction],
            ));
        }

        return $payment->fresh();
    }

    /**
     * `x-squad-encrypted-body` is the uppercase hex HMAC-SHA512 of the raw body, keyed with the secret key.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secretKey = $this->integrations->enabledConfig('squad')['secret_key'] ?? null;
        $signature = $request->header('x-squad-encrypted-body');

        if (! is_string($secretKey) || $secretKey === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(strtoupper(hash_hmac('sha512', $request->getContent(), $secretKey)), strtoupper($signature));
    }

    /**
     * Only successful charges change the ledger; other events are acknowledged and ignored.
     */
    public function parseWebhook(Request $request): ?PaymentWebhook
    {
        $event = json_decode($request->getContent(), true);
        $body = is_array($event) ? ($event['Body'] ?? null) : null;

        if (! is_array($body) || ($event['Event'] ?? null) !== 'charge_successful' || strtolower((string) ($body['transaction_status'] ?? '')) !== 'success') {
            return null;
        }

        return new PaymentWebhook(
            reference: (string) ($body['transaction_ref'] ?? $event['TransactionRef'] ?? ''),
            status: PaymentWebhook::STATUS_SUCCESSFUL,
            amount: $this->fromMinorUnits((int) ($body['amount'] ?? 0)),
            currency: (string) ($body['currency'] ?? ''),
            payload: $event,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function config(): array
    {
        return $this->integrations->enabledConfig('squad')
            ?? throw new PaymentGatewayException('Squad is not enabled.');
    }
}
