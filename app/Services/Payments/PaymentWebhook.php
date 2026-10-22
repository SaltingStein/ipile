<?php

namespace App\Services\Payments;

/**
 * A provider webhook reduced to the facts the ledger needs. Gateways build this only after
 * the signature has been verified.
 */
final readonly class PaymentWebhook
{
    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  'successful'|'failed'  $status  Final status reported by the provider.
     * @param  string  $amount  Amount the provider settled, as a decimal string (e.g. "5000.00").
     * @param  array<string, mixed>  $payload  The decoded webhook body, kept for audit.
     */
    public function __construct(
        public string $reference,
        public string $status,
        public string $amount,
        public string $currency,
        public array $payload,
    ) {}
}
