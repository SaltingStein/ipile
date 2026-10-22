<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Notifications\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified provider webhook to the payments ledger.
 *
 * A payment leaves `pending` exactly once: the row is locked, re-read and only updated while still
 * pending, so retried or concurrent deliveries cannot change a final status or credit twice.
 */
class PaymentWebhookProcessor
{
    public function apply(Payment $payment, PaymentWebhook $webhook): WebhookOutcome
    {
        if (! $this->matchesLedger($payment, $webhook)) {
            Log::warning('Payment webhook amount or currency does not match the ledger.', [
                'reference' => $payment->reference,
                'provider' => $payment->provider,
                'expected' => [$payment->amount, $payment->currency],
                'received' => [$webhook->amount, $webhook->currency],
            ]);

            return WebhookOutcome::AmountMismatch;
        }

        $outcome = DB::transaction(function () use ($payment, $webhook): WebhookOutcome {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return WebhookOutcome::AlreadyProcessed;
            }

            $locked->update([
                'status' => $webhook->status,
                'gateway_response' => array_merge((array) $locked->gateway_response, [
                    'webhook' => $webhook->payload,
                    'webhook_received_at' => now()->toIso8601String(),
                ]),
            ]);

            return WebhookOutcome::Applied;
        });

        // Only the delivery that settled the payment notifies, so retried webhooks never repeat the message.
        if ($outcome === WebhookOutcome::Applied) {
            $this->notifyPayer($payment, $webhook->status);
        }

        return $outcome;
    }

    private function notifyPayer(Payment $payment, string $status): void
    {
        Notify::event($status === PaymentWebhook::STATUS_SUCCESSFUL ? 'payment.succeeded' : 'payment.failed')
            ->to($payment->user)
            ->with([
                'amount' => strtoupper($payment->currency).' '.number_format((float) $payment->amount, 2),
                'reference' => $payment->reference,
            ])
            ->send();
    }

    /**
     * Compare in minor units (kobo/cents) so "5000", "5000.0" and "5000.00" are equal.
     */
    private function matchesLedger(Payment $payment, PaymentWebhook $webhook): bool
    {
        if (! is_numeric($webhook->amount)) {
            return false;
        }

        return (int) round((float) $payment->amount * 100) === (int) round((float) $webhook->amount * 100)
            && strtoupper($payment->currency) === strtoupper($webhook->currency);
    }
}
