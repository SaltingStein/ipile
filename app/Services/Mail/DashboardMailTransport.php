<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The `dashboard` mailer: sends through the email provider chosen in Admin → Integrations (see
 * {@see MailProviders}), otherwise through the fallback mailer from `.env` (e.g. `log` in development).
 *
 * The choice is made on every send, so switching providers takes effect immediately, including in
 * long-running queue workers.
 */
class DashboardMailTransport implements TransportInterface
{
    public function __construct(
        private MailProviders $providers,
        private TransportInterface $fallback,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $provider = $this->providers->active();
        $transport = $provider === null ? $this->fallback : $this->providers->transport($provider);

        return $transport->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'dashboard';
    }
}
