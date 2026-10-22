<?php

namespace App\Services\Sms;

/**
 * Delivers one SMS to the provider. Implementations talk to the network, so tests replace this contract.
 */
interface SmsTransport
{
    /**
     * @param  array<string, mixed>  $config  The provider's settings from Admin → Integrations.
     * @return string The provider's message id, used to match delivery receipts.
     *
     * @throws SmsException
     */
    public function send(array $config, string $to, string $text): string;

    /**
     * Open and close a session to prove the settings work, without sending a message.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws SmsException
     */
    public function checkConnection(array $config): void;
}
