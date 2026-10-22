<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * The authenticated user's payments. Every query is scoped to the token owner.
 */
class PaymentController extends Controller
{
    /**
     * GET /api/v1/payments — newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = $request->user()->payments()
            ->latest()
            ->latest('id')
            ->paginate(20);

        return PaymentResource::collection($payments);
    }

    /**
     * POST /api/v1/payments — start a payment with the chosen gateway.
     */
    public function store(StorePaymentRequest $request, PaymentGatewayResolver $gateways): JsonResponse
    {
        try {
            $payment = $gateways->resolve($request->string('provider')->toString())->charge([
                'user_id' => $request->user()->getKey(),
                'email' => $request->user()->email,
                'name' => $request->user()->display_name,
                'amount' => $request->input('amount'),
                'currency' => $request->string('currency', 'NGN')->toString(),
                // Providers such as Paystack only accept letters, digits, "-", "." and "=" in references.
                'reference' => 'pay-'.Str::lower((string) Str::ulid()),
                'payment_method' => $request->input('payment_method'),
            ]);
        } catch (PaymentGatewayException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return (new PaymentResource($payment->fresh()))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v1/payments/{reference}/verify — ask the provider for the latest status (e.g. after the
     * payer returns from checkout). Final results are applied once; the webhook may already have done so.
     */
    public function verify(Request $request, string $reference, PaymentGatewayResolver $gateways): PaymentResource|JsonResponse
    {
        $payment = $request->user()->payments()->where('reference', $reference)->firstOrFail();

        try {
            $payment = $gateways->resolve($payment->provider)->verify($payment->reference);
        } catch (PaymentGatewayException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return new PaymentResource($payment);
    }

    /**
     * GET /api/v1/payments/{reference} — another user's reference returns 404 so its existence is not revealed.
     */
    public function show(Request $request, string $reference): PaymentResource
    {
        return new PaymentResource(
            $request->user()->payments()->where('reference', $reference)->firstOrFail()
        );
    }
}
