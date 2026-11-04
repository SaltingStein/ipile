<?php

namespace App\Services\Mail;

use App\Services\BrandingSettings;
use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * "Test connection" for Resend. Keys limited to "Sending access" cannot call any other endpoint, so this
 * sends a short test email to the configured From address.
 */
class ResendTester implements IntegrationTester
{
    public function __construct(private ResendClient $client) {}

    public function test(array $config): IntegrationTestResult
    {
        $appName = app(BrandingSettings::class)->appName();
        $email = (new Email)
            ->to(new Address($config['from_address'], (string) ($config['from_name'] ?? '')))
            ->subject(__(':app: Resend connection test', ['app' => $appName]))
            ->text(__('This email confirms that :app can send through Resend.', ['app' => $appName]));

        try {
            $this->client->send($config, $email);
        } catch (ResendException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('Resend accepted a test email to :address.', ['address' => $config['from_address']]));
    }
}
