<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationManager;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony mailer transport that delivers through ZeptoMail with the settings saved in the dashboard.
 */
class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(
        private IntegrationManager $integrations,
        private ZeptoMailClient $client,
    ) {
        parent::__construct();
    }

    /**
     * @throws ZeptoMailException
     */
    protected function doSend(SentMessage $message): void
    {
        $config = $this->integrations->enabledConfig('zeptomail')
            ?? throw new ZeptoMailException('ZeptoMail is not enabled in Admin → Integrations.');

        $requestId = $this->client->send($config, MessageConverter::toEmail($message->getOriginalMessage()));

        if ($requestId !== null) {
            $message->setMessageId($requestId);
        }
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}
