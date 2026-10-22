<?php

namespace App\Services\Payments\Opay;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use App\Services\Payments\PaymentGatewayException;

/**
 * "Test connection" for OPay: a signed, read-only status query that checks the merchant ID and secret key.
 */
class OpayTester implements IntegrationTester
{
    public function __construct(private OpayClient $client) {}

    public function test(array $config): IntegrationTestResult
    {
        try {
            $this->client->checkCredentials($config);
        } catch (PaymentGatewayException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('OPay accepted the merchant ID and secret key (:environment).', ['environment' => $config['environment'] ?? 'sandbox']));
    }
}
