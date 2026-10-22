<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Payments\WebhookOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/webhooks/{provider}: asynchronous payment confirmation from a provider.
 *
 * The signature is checked before anything else, so unsigned callers cannot probe references or
 * change the ledger. Verified events are applied once by {@see PaymentWebhookProcessor}.
 */
class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PaymentGatewayResolver $gateways,
        PaymentWebhookProcessor $processor,
    ): JsonResponse {
        $provider = strtolower($provider);

        if (! $gateways->supports($provider)) {
            return response()->json(['message' => 'Unsupported provider.'], 404);
        }

        $gateway = $gateways->resolve($provider);

        if (! $gateway->verifyWebhookSignature($request)) {
            Log::warning('Rejected payment webhook with a missing or invalid signature.', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $webhook = $gateway->parseWebhook($request);

        if ($webhook === null) {
            return response()->json(['message' => 'Event ignored.']);
        }

        $payment = Payment::query()
            ->where('provider', $provider)
            ->where('reference', $webhook->reference)
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return match ($processor->apply($payment, $webhook)) {
            WebhookOutcome::Applied => response()->json(['message' => 'Webhook processed.']),
            WebhookOutcome::AlreadyProcessed => response()->json(['message' => 'Payment already processed.']),
            WebhookOutcome::AmountMismatch => response()->json(['message' => 'Amount or currency does not match the payment.'], 422),
        };
    }
}
