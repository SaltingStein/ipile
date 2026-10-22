<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment started by the authenticated user. The reference and owner are set by the server.
 */
class StorePaymentRequest extends FormRequest
{
    /**
     * Any authenticated, verified user may start a payment; the route middleware enforces that.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PaymentGatewayResolver $gateways): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['sometimes', 'string', Rule::in(['NGN', 'USD'])],
            'provider' => ['required', 'string', Rule::in($gateways->availableProviders())],
            'payment_method' => ['nullable', 'string', Rule::in(['card', 'bank_transfer', 'ussd'])],
        ];
    }
}
