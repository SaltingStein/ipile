<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\Integrations\IntegrationManager;
use App\Services\Sms\SmsException;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one logged SMS to the SMSC, retrying transient failures.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before each retry.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public SmsMessage $message) {}

    /**
     * Send the message once; a message that already left the queue is never sent twice.
     *
     * @throws SmsException
     */
    public function handle(IntegrationManager $integrations, SmsTransport $transport): void
    {
        $this->message->refresh();

        if ($this->message->status !== SmsMessage::STATUS_QUEUED) {
            return;
        }

        $config = $integrations->enabledConfig(SmsSender::PROVIDER);

        if ($config === null) {
            $this->message->update(['status' => SmsMessage::STATUS_FAILED, 'error' => 'SMS was disabled before the message was sent.']);

            return;
        }

        try {
            $messageId = $transport->send($config, $this->message->to, $this->message->body);
        } catch (SmsException $exception) {
            $this->message->update(['error' => mb_substr($exception->getMessage(), 0, 500)]);

            throw $exception;
        }

        $this->message->update([
            'status' => SmsMessage::STATUS_SENT,
            'provider_message_id' => $messageId,
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * All retries are used up.
     */
    public function failed(?Throwable $exception): void
    {
        $this->message->update([
            'status' => SmsMessage::STATUS_FAILED,
            'error' => mb_substr($exception?->getMessage() ?? 'Sending failed.', 0, 500),
        ]);
    }
}
