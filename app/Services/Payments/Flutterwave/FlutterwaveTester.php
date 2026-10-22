<?php

namespace App\Services\Payments\Flutterwave;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use App\Services\Payments\PaymentGatewayException;

/**
 * "Test connection" for Flutterwave: a read-only wallet-balance request with the saved secret key.
 */
class FlutterwaveTester implements IntegrationTester
{
    public function __construct(private FlutterwaveClient $client) {}

    public function test(array $config): IntegrationTestResult
    {
        try {
            $this->client->checkCredentials($config);
        } catch (PaymentGatewayException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('Flutterwave accepted the secret key.'));
    }
}
