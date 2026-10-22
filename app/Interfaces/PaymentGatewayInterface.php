<?php

namespace App\Interfaces;

use App\Models\Payment;
use App\Services\Payments\PaymentWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Contract for payment providers so the app can swap gateways without rewriting business logic.
 */
interface PaymentGatewayInterface
{
    public function initialize(array $config = []): self;

    public function charge(array $payload): Payment;

    public function verify(string $reference): Payment;

    public function getProviderName(): string;

    /**
     * Whether new payments can be started: the adapter is implemented and enabled in Admin → Integrations.
     */
    public function isAvailable(): bool;

    public function getSupportedCurrencies(): Collection;

    /**
     * Check the provider's signature over the raw request body. Must return false when the
     * provider's webhook secret is not configured (fail closed).
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Turn a verified webhook into a ledger update, or null for events the ledger ignores.
     */
    public function parseWebhook(Request $request): ?PaymentWebhook;
}
