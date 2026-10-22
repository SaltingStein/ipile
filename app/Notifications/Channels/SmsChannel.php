<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Laravel notification channel for SMS. A notification opts in with `via()` returning
 * `SmsChannel::class` and a `toSms(object $notifiable): string` method; the recipient's number comes
 * from `routeNotificationForSms()` (users) or `Notification::route(SmsChannel::class, '0803...')`.
 */
class SmsChannel
{
    public function __construct(private SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor(self::class, $notification)
            ?? $notifiable->routeNotificationFor('sms', $notification);

        if (blank($to)) {
            return;
        }

        if (! method_exists($notification, 'toSms')) {
            throw new LogicException($notification::class.' must define toSms() to use the SMS channel.');
        }

        $this->sender->queue((string) $to, (string) $notification->toSms($notifiable), $notifiable instanceof User ? $notifiable : null);
    }
}
