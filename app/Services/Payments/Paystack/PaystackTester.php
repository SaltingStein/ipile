<?php

namespace App\Services\Payments\Paystack;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use App\Services\Payments\PaymentGatewayException;

/**
 * "Test connection" for Paystack: a read-only balance request with the saved secret key.
 */
class PaystackTester implements IntegrationTester
{
    public function __construct(private PaystackClient $client) {}

    public function test(array $config): IntegrationTestResult
    {
        try {
            $this->client->checkCredentials($config);
        } catch (PaymentGatewayException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('Paystack accepted the secret key.'));
    }
}
