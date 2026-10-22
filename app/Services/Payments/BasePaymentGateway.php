<?php

namespace App\Services\Payments;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared implementation for gateway-specific adapters.
 *
 * The provider adapters are still stubs: they record a pending payment but do not call the
 * provider, and they reject every webhook until their real signature scheme and dashboard-managed
 * secret are implemented (backlog CORE-801/CORE-802).
 */
abstract class BasePaymentGateway implements PaymentGatewayInterface
{
    protected array $config = [];

    public function initialize(array $config = []): self
    {
        $this->config = $config;

        return $this;
    }

    public function charge(array $payload): Payment
    {
        $payment = Payment::create([
            'user_id' => $payload['user_id'] ?? null,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'NGN',
            'reference' => $payload['reference'] ?? uniqid('pay_', true),
            'provider' => $this->getProviderName(),
            'status' => 'pending',
            'payment_method' => $payload['payment_method'] ?? null,
            'gateway_response' => [
                'provider' => $this->getProviderName(),
                'request' => $payload,
            ],
        ]);

        return $payment;
    }

    /**
     * Return the stored payment. Confirming a payment requires asking the provider, which the stub
     * adapters cannot do, so the status is never changed here.
     */
    public function verify(string $reference): Payment
    {
        return Payment::query()
            ->where('provider', $this->getProviderName())
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /**
     * Stub adapters cannot take real payments.
     */
    public function isAvailable(): bool
    {
        return false;
    }

    public function getSupportedCurrencies(): Collection
    {
        return collect(['NGN', 'USD']);
    }

    /**
     * Naira (or dollars) to kobo (or cents), as providers expect integer minor units.
     */
    protected function toMinorUnits(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Kobo (or cents) back to a two-decimal string for comparing with the ledger.
     */
    protected function fromMinorUnits(int $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }

    /**
     * Fail closed until the provider's signature scheme is implemented.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * No webhook format is understood until the provider adapter implements it.
     */
    public function parseWebhook(Request $request): ?PaymentWebhook
    {
        return null;
    }
}
