<?php

namespace App\Services\Sms;

use App\Jobs\SendSmsJob;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Integrations\IntegrationManager;
use InvalidArgumentException;

/**
 * Entry point for sending SMS: validates the number, logs the message and queues delivery.
 */
class SmsSender
{
    public const PROVIDER = 'smpp';

    public function __construct(private IntegrationManager $integrations) {}

    public function isEnabled(): bool
    {
        return $this->integrations->isEnabled(self::PROVIDER);
    }

    /**
     * Queue an SMS. The number may be local (0803…) or international (+234803…).
     *
     * @throws SmsException When SMS is not enabled in Admin → Integrations.
     * @throws InvalidArgumentException When the number cannot be normalised.
     */
    public function queue(string $to, string $text, ?User $user = null): SmsMessage
    {
        $config = $this->integrations->enabledConfig(self::PROVIDER)
            ?? throw new SmsException('SMS is not enabled. Configure TelKo SMS (SMPP) in Admin → Integrations.');

        $number = PhoneNumber::normalize($to, (string) ($config['default_country_code'] ?? '234'))
            ?? throw new InvalidArgumentException("[{$to}] is not a valid phone number.");

        $message = SmsMessage::create([
            'user_id' => $user?->getKey(),
            'provider' => self::PROVIDER,
            'to' => $number,
            'sender' => (string) $config['source_address'],
            'body' => $text,
            'status' => SmsMessage::STATUS_QUEUED,
        ]);

        SendSmsJob::dispatch($message);

        return $message;
    }
}
