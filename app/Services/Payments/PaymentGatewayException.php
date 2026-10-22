<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * A payment provider could not be reached or refused a request. The message is safe to show to the payer.
 */
class PaymentGatewayException extends RuntimeException {}
