<?php

namespace App\Services\Payments\Flutterwave;

use App\Models\Payment;
use App\Services\BrandingSettings;
use App\Services\Integrations\IntegrationManager;
use App\Services\Payments\BasePaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentWebhook;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Http\Request;

/**
 * Flutterwave Standard: the payer is sent to Flutterwave's hosted page (`checkout_url`), and the payment
 * is confirmed by the `charge.completed` webhook or by calling verify. Credentials come from
 * Admin → Integrations. Flutterwave amounts are in naira, unlike the kobo used by the other gateways.
 */
class FlutterwaveGateway extends BasePaymentGateway
{
    public function __construct(
        private IntegrationManager $integrations,
        private FlutterwaveClient $client,
        private PaymentWebhookProcessor $processor,
    ) {}

    public function getProviderName(): string
    {
        return 'flutterwave';
    }

    public function isAvailable(): bool
    {
        return $this->integrations->isEnabled('flutterwave');
    }

    /**
     * Record a pending payment and create a Flutterwave payment link for it.
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
            $link = $this->client->createPaymentLink($config, [
                'tx_ref' => $payment->reference,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'redirect_url' => $config['redirect_url'],
                'customer' => array_filter(['email' => $payload['email'], 'name' => $payload['name'] ?? null]),
                'customizations' => ['title' => app(BrandingSettings::class)->appName()],
                'meta' => ['payment_id' => $payment->id],
            ]);
        } catch (PaymentGatewayException $exception) {
            $payment->update(['status' => 'failed', 'gateway_response' => [...(array) $payment->gateway_response, 'error' => $exception->getMessage()]]);

            throw $exception;
        }

        $payment->update(['gateway_response' => [...(array) $payment->gateway_response, 'checkout_url' => $link['link'] ?? null]]);

        return $payment;
    }

    /**
     * Ask Flutterwave for the transaction status and apply a final result once; pending stays pending.
     *
     * @throws PaymentGatewayException
     */
    public function verify(string $reference): Payment
    {
        $payment = parent::verify($reference);
        $transaction = $this->client->verifyByReference($this->config(), $reference);
        $status = $this->finalStatus($transaction['status'] ?? null);

        if ($status !== null) {
            $this->processor->apply($payment, new PaymentWebhook(
                reference: $reference,
                status: $status,
                amount: $this->decimal($transaction['amount'] ?? 0),
                currency: (string) ($transaction['currency'] ?? ''),
                payload: ['verify' => $transaction],
            ));
        }

        return $payment->fresh();
    }

    /**
     * Flutterwave sends the dashboard "secret hash" unchanged in the `verif-hash` header; compare in constant time.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secretHash = $this->integrations->enabledConfig('flutterwave')['webhook_secret_hash'] ?? null;
        $signature = $request->header('verif-hash');

        if (! is_string($secretHash) || $secretHash === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals($secretHash, $signature);
    }

    /**
     * Only `charge.completed` with a final status changes the ledger; other events are ignored.
     */
    public function parseWebhook(Request $request): ?PaymentWebhook
    {
        $event = json_decode($request->getContent(), true);
        $data = is_array($event) ? ($event['data'] ?? null) : null;

        if (! is_array($data) || ($event['event'] ?? null) !== 'charge.completed') {
            return null;
        }

        $status = $this->finalStatus($data['status'] ?? null);

        if ($status === null) {
            return null;
        }

        return new PaymentWebhook(
            reference: (string) ($data['tx_ref'] ?? ''),
            status: $status,
            amount: $this->decimal($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            payload: $event,
        );
    }

    private function finalStatus(mixed $status): ?string
    {
        return match ($status) {
            'successful' => PaymentWebhook::STATUS_SUCCESSFUL,
            'failed' => PaymentWebhook::STATUS_FAILED,
            default => null,
        };
    }

    /**
     * Flutterwave sends major units (e.g. 100 or "100.50"); normalise to a two-decimal string.
     */
    private function decimal(mixed $amount): string
    {
        return is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function config(): array
    {
        return $this->integrations->enabledConfig('flutterwave')
            ?? throw new PaymentGatewayException('Flutterwave is not enabled.');
    }
}
