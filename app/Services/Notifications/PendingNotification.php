<?php

namespace App\Services\Notifications;

use App\Notifications\Channels\SmsChannel;
use App\Notifications\EventNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Collects the recipients and placeholder values for one event, then sends it. Built by {@see Notify::event()}.
 */
class PendingNotification
{
    /** @var list<object> */
    private array $notifiables = [];

    /** @var array<string, string> channel => address */
    private array $routes = [];

    /** @var array<string, scalar|null> */
    private array $data = [];

    public function __construct(private NotificationEvent $event) {}

    /**
     * Send to users (or any notifiable). Users get email at their address and SMS at their phone number.
     *
     * @param  object|iterable<object>|null  $notifiables
     */
    public function to(object|iterable|null $notifiables): static
    {
        foreach (is_iterable($notifiables) ? $notifiables : [$notifiables] as $notifiable) {
            if ($notifiable !== null) {
                $this->notifiables[] = $notifiable;
            }
        }

        return $this;
    }

    /**
     * Send email to an address that may not belong to an account (e.g. an invitation).
     */
    public function toEmail(?string $email): static
    {
        if (filled($email)) {
            $this->routes['mail'] = $email;
        }

        return $this;
    }

    /**
     * Send SMS to a number that may not belong to an account. Local (0803…) or international (+234…) format.
     */
    public function toPhone(?string $phone): static
    {
        if (filled($phone)) {
            $this->routes[SmsChannel::class] = $phone;
        }

        return $this;
    }

    /**
     * Placeholder values for the templates, e.g. `['amount' => 'NGN 5,000.00']` for `{amount}`.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function with(array $data): static
    {
        $this->data = [...$this->data, ...$data];

        return $this;
    }

    /**
     * Send on the channels switched on for this event. A delivery problem is reported, never thrown,
     * so a failed email or SMS cannot undo the action that triggered it.
     */
    public function send(): void
    {
        $notification = new EventNotification($this->event->key, $this->data);

        try {
            if ($this->notifiables !== []) {
                Notification::send($this->notifiables, $notification);
            }

            if ($this->routes !== []) {
                $anonymous = new AnonymousNotifiable;

                foreach ($this->routes as $channel => $address) {
                    $anonymous->route($channel, $address);
                }

                $anonymous->notify($notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
