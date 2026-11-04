<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationManager;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony mailer transport that delivers through Resend with the settings saved in the dashboard.
 */
class ResendTransport extends AbstractTransport
{
    public const PROVIDER = 'resend';

    public function __construct(
        private IntegrationManager $integrations,
        private ResendClient $client,
    ) {
        parent::__construct();
    }

    /**
     * @throws ResendException
     */
    protected function doSend(SentMessage $message): void
    {
        $config = $this->integrations->enabledConfig(self::PROVIDER)
            ?? throw new ResendException('Resend is not enabled in Admin → Integrations.');

        $emailId = $this->client->send($config, MessageConverter::toEmail($message->getOriginalMessage()));

        if ($emailId !== null) {
            $message->setMessageId($emailId);
        }
    }

    public function __toString(): string
    {
        return self::PROVIDER;
    }
}
