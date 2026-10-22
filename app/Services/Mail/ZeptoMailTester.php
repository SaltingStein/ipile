<?php

namespace App\Services\Mail;

use App\Services\BrandingSettings;
use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * "Test connection" for ZeptoMail. ZeptoMail has no credential-check endpoint, so this sends a short
 * test email to the configured From address.
 */
class ZeptoMailTester implements IntegrationTester
{
    public function __construct(private ZeptoMailClient $client) {}

    public function test(array $config): IntegrationTestResult
    {
        $email = (new Email)
            ->to(new Address($config['from_address'], (string) ($config['from_name'] ?? '')))
            ->subject(__(':app: ZeptoMail connection test', ['app' => app(BrandingSettings::class)->appName()]))
            ->text(__('This email confirms that :app can send through ZeptoMail.', ['app' => app(BrandingSettings::class)->appName()]));

        try {
            $this->client->send($config, $email);
        } catch (ZeptoMailException $exception) {
            return IntegrationTestResult::failed($exception->getMessage());
        }

        return IntegrationTestResult::passed(__('ZeptoMail accepted a test email to :address.', ['address' => $config['from_address']]));
    }
}
