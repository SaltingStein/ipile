<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationManager;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The `dashboard` mailer: sends through the email provider enabled in Admin → Integrations, otherwise
 * through the fallback mailer from `.env` (e.g. `log` in development).
 *
 * The choice is made on every send, so enabling or disabling a provider takes effect immediately,
 * including in long-running queue workers.
 */
class DashboardMailTransport implements TransportInterface
{
    public function __construct(
        private IntegrationManager $integrations,
        private ZeptoMailTransport $zeptoMail,
        private TransportInterface $fallback,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $transport = $this->integrations->isEnabled('zeptomail') ? $this->zeptoMail : $this->fallback;

        return $transport->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'dashboard';
    }
}
