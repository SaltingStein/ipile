<?php

namespace App\Services\Payments\Squad;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use App\Services\Payments\PaymentGatewayException;

/**
 * "Test connection" for Squad: a read-only verify call that only checks the key is accepted.
 */
class SquadTester implements IntegrationTester
{
    public function __construct(private SquadClient $client) {}

    public function test(array $config): IntegrationTestResult
    {
        try {
            $this->client->checkCredentials($config);
        } catch (PaymentGatewayException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('Squad accepted the secret key (:environment).', ['environment' => $config['environment'] ?? 'sandbox']));
    }
}
