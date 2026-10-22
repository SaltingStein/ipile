<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as shown to its owner. The raw gateway response stays internal.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            // Hosted checkout page to send the payer to (null for gateways without one).
            'checkout_url' => $this->status === 'pending' ? ($this->gateway_response['checkout_url'] ?? null) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
