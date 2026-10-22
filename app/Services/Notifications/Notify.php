<?php

namespace App\Services\Notifications;

/**
 * The one way to send an email or SMS about something that happened:
 *
 *     Notify::event('payment.succeeded')
 *         ->to($user)                     // or ->toEmail('ada@example.com')->toPhone('08031234567')
 *         ->with(['amount' => 'NGN 5,000.00', 'reference' => $payment->reference])
 *         ->send();
 *
 * Events are listed in {@see NotificationCatalog}; administrators choose channels and wording in
 * Admin → Notifications. In tests, `Notification::fake()` captures an `EventNotification`.
 */
class Notify
{
    public static function event(string $key): PendingNotification
    {
        return new PendingNotification(app(NotificationCatalog::class)->find($key));
    }
}
