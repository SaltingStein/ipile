<?php

namespace App\Services\Payments;

/**
 * Moniepoint adapter for the unified payments layer.
 */
class MoniepointGateway extends BasePaymentGateway
{
    public function getProviderName(): string
    {
        return 'moniepoint';
    }
}
