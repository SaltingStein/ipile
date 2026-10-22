<?php

namespace App\Services\Payments\Paystack;

use App\Models\Payment;
use App\Services\Integrations\IntegrationManager;
use App\Services\Payments\BasePaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentWebhook;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Http\Request;

/**
 * Paystack checkout: the payer is sent to Paystack's hosted page (`checkout_url`), and the payment is
 * confirmed by the signed `charge.success` webhook or by calling verify.
 * Credentials come from Admin → Integrations.
 */
class PaystackGateway extends BasePaymentGateway
{
    public function __construct(
        private IntegrationManager $integrations,
        private PaystackClient $client,
        private PaymentWebhookProcessor $processor,
    ) {}

    public function getProviderName(): string
    {
        return 'paystack';
    }

    public function isAvailable(): bool
    {
        return $this->integrations->isEnabled('paystack');
    }

    /**
     * Record a pending payment and open a Paystack checkout for it.
     *
     * @param  array{user_id: int, email: string, amount: string|int|float, currency: string, reference: string, payment_method?: ?string}  $payload
     *
     * @throws PaymentGatewayException
     */
    public function charge(array $payload): Payment
    {
        $config = $this->config();
        $payment = parent::charge($payload);

        try {
            $checkout = $this->client->initialize($config, array_filter([
                'email' => $payload['email'],
                'amount' => $this->toMinorUnits($payment->amount),
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => $config['callback_url'] ?? null,
                'metadata' => ['payment_id' => $payment->id],
            ]));
        } catch (PaymentGatewayException $exception) {
            $payment->update(['status' => 'failed', 'gateway_response' => [...(array) $payment->gateway_response, 'error' => $exception->getMessage()]]);

            throw $exception;
        }

        $payment->update(['gateway_response' => [
            ...(array) $payment->gateway_response,
            'checkout_url' => $checkout['authorization_url'],
            'access_code' => $checkout['access_code'],
        ]]);

        return $payment;
    }

    /**
     * Ask Paystack for the transaction's status and apply a final result once (success or failed).
     * Abandoned or in-progress transactions leave the payment pending.
     *
     * @throws PaymentGatewayException
     */
    public function verify(string $reference): Payment
    {
        $payment = parent::verify($reference);
        $transaction = $this->client->verify($this->config(), $reference);

        $status = match ($transaction['status'] ?? null) {
            'success' => PaymentWebhook::STATUS_SUCCESSFUL,
            'failed' => PaymentWebhook::STATUS_FAILED,
            default => null,
        };

        if ($status !== null) {
            $this->processor->apply($payment, new PaymentWebhook(
                reference: $reference,
                status: $status,
                amount: $this->fromMinorUnits((int) ($transaction['amount'] ?? 0)),
                currency: (string) ($transaction['currency'] ?? ''),
                payload: ['verify' => $transaction],
            ));
        }

        return $payment->fresh();
    }

    /**
     * `x-paystack-signature` is the hex HMAC-SHA512 of the raw body, keyed with the secret key.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secretKey = $this->integrations->enabledConfig('paystack')['secret_key'] ?? null;
        $signature = $request->header('x-paystack-signature');

        if (! is_string($secretKey) || $secretKey === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $request->getContent(), $secretKey), strtolower($signature));
    }

    /**
     * Only `charge.success` changes the ledger; other events are acknowledged and ignored.
     */
    public function parseWebhook(Request $request): ?PaymentWebhook
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ($event['event'] ?? null) !== 'charge.success' || ($event['data']['status'] ?? null) !== 'success') {
            return null;
        }

        return new PaymentWebhook(
            reference: (string) ($event['data']['reference'] ?? ''),
            status: PaymentWebhook::STATUS_SUCCESSFUL,
            amount: $this->fromMinorUnits((int) ($event['data']['amount'] ?? 0)),
            currency: (string) ($event['data']['currency'] ?? ''),
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
        return $this->integrations->enabledConfig('paystack')
            ?? throw new PaymentGatewayException('Paystack is not enabled.');
    }
}
