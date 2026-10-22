<?php

namespace App\Services\Sms;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;

/**
 * "Test connection" for SMPP: connect and bind with the saved settings, then unbind. No SMS is sent.
 */
class SmppTester implements IntegrationTester
{
    public function __construct(private SmsTransport $transport) {}

    public function test(array $config): IntegrationTestResult
    {
        try {
            $this->transport->checkConnection($config);
        } catch (SmsException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('Connected and bound to :host as :system_id.', [
            'host' => $config['host'],
            'system_id' => $config['system_id'],
        ]));
    }
}
