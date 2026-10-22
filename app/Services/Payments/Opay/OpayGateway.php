<?php

namespace App\Services\Payments\Opay;

use App\Models\Payment;
use App\Services\BrandingSettings;
use App\Services\Integrations\IntegrationManager;
use App\Services\Payments\BasePaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentWebhook;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Http\Request;

/**
 * OPay cashier (Nigeria): the payer is sent to OPay's hosted cashier (`checkout_url`), and the payment is
 * confirmed by OPay's signed status callback or by calling verify.
 *
 * OPay is told to send callbacks to this app's /api/webhooks/opay, so nothing needs setting in OPay's dashboard.
 */
class OpayGateway extends BasePaymentGateway
{
    private const COUNTRY = 'NG';

    public function __construct(
        private IntegrationManager $integrations,
        private OpayClient $client,
        private PaymentWebhookProcessor $processor,
    ) {}

    public function getProviderName(): string
    {
        return 'opay';
    }

    public function isAvailable(): bool
    {
        return $this->integrations->isEnabled('opay');
    }

    /**
     * Record a pending payment and open an OPay cashier for it.
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
            $cashier = $this->client->createCashier($config, [
                'country' => self::COUNTRY,
                'reference' => $payment->reference,
                'amount' => ['total' => $this->toMinorUnits($payment->amount), 'currency' => $payment->currency],
                'returnUrl' => $config['return_url'],
                'callbackUrl' => route('webhooks.payment', 'opay'),
                'userInfo' => array_filter([
                    'userId' => (string) $payload['user_id'],
                    'userEmail' => $payload['email'],
                    'userName' => $payload['name'] ?? null,
                ]),
                'product' => [
                    'name' => app(BrandingSettings::class)->appName(),
                    'description' => 'Payment '.$payment->reference,
                ],
            ]);
        } catch (PaymentGatewayException $exception) {
            $payment->update(['status' => 'failed', 'gateway_response' => [...(array) $payment->gateway_response, 'error' => $exception->getMessage()]]);

            throw $exception;
        }

        $payment->update(['gateway_response' => [
            ...(array) $payment->gateway_response,
            'checkout_url' => $cashier['cashierUrl'] ?? null,
            'order_no' => $cashier['orderNo'] ?? null,
        ]]);

        return $payment;
    }

    /**
     * Ask OPay for the payment status and apply a final result once. SUCCESS is paid; FAIL and CLOSE
     * (the cashier order was closed and can no longer be paid) are failed; INITIAL/PENDING stay pending.
     *
     * @throws PaymentGatewayException
     */
    public function verify(string $reference): Payment
    {
        $payment = parent::verify($reference);
        $order = $this->client->status($this->config(), $reference);

        $status = match ($order['status'] ?? null) {
            'SUCCESS' => PaymentWebhook::STATUS_SUCCESSFUL,
            'FAIL', 'CLOSE' => PaymentWebhook::STATUS_FAILED,
            default => null,
        };

        if ($status !== null) {
            $this->processor->apply($payment, new PaymentWebhook(
                reference: $reference,
                status: $status,
                amount: $this->fromMinorUnits((int) ($order['amount']['total'] ?? 0)),
                currency: (string) ($order['amount']['currency'] ?? ''),
                payload: ['verify' => $order],
            ));
        }

        return $payment->fresh();
    }

    /**
     * The body's `sha512` is an HMAC-SHA3-512, keyed with the secret key, of a fixed string built from the
     * payload: {Amount:"…",Currency:"…",Reference:"…",Refunded:t|f,Status:"…",Timestamp:"…",Token:"…",TransactionID:"…"}.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secretKey = $this->integrations->enabledConfig('opay')['secret_key'] ?? null;
        $callback = json_decode($request->getContent(), true);

        if (! is_string($secretKey) || $secretKey === '' || ! is_array($callback) || ! is_array($callback['payload'] ?? null) || ! is_string($callback['sha512'] ?? null)) {
            return false;
        }

        $payload = $callback['payload'];
        $signedContent = sprintf(
            '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
            $payload['amount'] ?? '',
            $payload['currency'] ?? '',
            $payload['reference'] ?? '',
            ($payload['refunded'] ?? false) === true ? 't' : 'f',
            $payload['status'] ?? '',
            $payload['timestamp'] ?? '',
            $payload['token'] ?? '',
            $payload['transactionId'] ?? '',
        );

        return hash_equals(hash_hmac('sha3-512', $signedContent, $secretKey), strtolower($callback['sha512']));
    }

    /**
     * Only final, non-refund transaction-status callbacks change the ledger. Amounts are in kobo.
     */
    public function parseWebhook(Request $request): ?PaymentWebhook
    {
        $callback = json_decode($request->getContent(), true);
        $payload = is_array($callback) ? ($callback['payload'] ?? null) : null;

        if (! is_array($payload) || ($callback['type'] ?? null) !== 'transaction-status' || ($payload['refunded'] ?? false) === true) {
            return null;
        }

        $status = match ($payload['status'] ?? null) {
            'SUCCESS' => PaymentWebhook::STATUS_SUCCESSFUL,
            'FAIL' => PaymentWebhook::STATUS_FAILED,
            default => null,
        };

        if ($status === null) {
            return null;
        }

        return new PaymentWebhook(
            reference: (string) ($payload['reference'] ?? ''),
            status: $status,
            amount: $this->fromMinorUnits((int) ($payload['amount'] ?? 0)),
            currency: (string) ($payload['currency'] ?? ''),
            payload: $callback,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function config(): array
    {
        return $this->integrations->enabledConfig('opay')
            ?? throw new PaymentGatewayException('OPay is not enabled.');
    }
}
