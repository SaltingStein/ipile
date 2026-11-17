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
 * Events are listed in {@see NotificationCatalog}; add your own from a service provider's boot():
 *
 *     Notify::define(new NotificationEvent('orders.shipped', 'Orders', 'Order shipped',
 *         'Sent when an order leaves the warehouse.', [NotificationEvent::MAIL, NotificationEvent::SMS],
 *         [NotificationEvent::MAIL], ['reference' => 'ORD-1042'],
 *         'Your order {reference} has shipped', 'Your order {reference} is on its way.',
 *         '{app_name}: order {reference} has shipped.'));
 *
 * Administrators choose channels and wording in Admin → Notifications. In tests, `Notification::fake()`
 * captures an `EventNotification`.
 */
class Notify
{
    public static function event(string $key): PendingNotification
    {
        return new PendingNotification(app(NotificationCatalog::class)->find($key));
    }

    /**
     * Add a notification event. Throws if the key is taken, so a product cannot replace an Ìpìlẹ̀ event by accident.
     */
    public static function define(NotificationEvent $event): void
    {
        app(NotificationCatalog::class)->register($event);
    }

    /**
     * Replace an existing event (for example to change an Ìpìlẹ̀ event's default wording or channels).
     */
    public static function override(NotificationEvent $event): void
    {
        app(NotificationCatalog::class)->register($event, override: true);
    }
}
