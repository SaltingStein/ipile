<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Services\BrandingSettings;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationSettings;
use App\Services\Sms\SmsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a catalog event using the channels and wording chosen in Admin → Notifications.
 * Send it with `Notify::event('key')`, not directly.
 */
class EventNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $data  Placeholder values for the templates.
     */
    public function __construct(public string $event, public array $data = []) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        foreach (app(NotificationSettings::class)->config($this->definition())['channels'] as $channel) {
            if ($channel === NotificationEvent::MAIL) {
                $channels[] = 'mail';
            } elseif ($channel === NotificationEvent::SMS && app(SmsSender::class)->isEnabled()) {
                $channels[] = SmsChannel::class;
            }
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return self::mailMessage($this->definition(), $this->dataFor($notifiable));
    }

    public function toSms(object $notifiable): string
    {
        $settings = app(NotificationSettings::class);

        return $settings->render((string) $settings->config($this->definition())['sms_body'], $this->dataFor($notifiable));
    }

    /**
     * Build the email for an event. Also used for Laravel's own verification and password reset emails.
     *
     * @param  array<string, scalar|null>  $data
     */
    public static function mailMessage(NotificationEvent $event, array $data): MailMessage
    {
        $settings = app(NotificationSettings::class);
        $config = $settings->config($event);
        $paragraphs = preg_split('/\R{2,}/', trim($settings->render($config['mail_body'], $data))) ?: [];

        $message = (new MailMessage)
            ->subject($settings->render($config['mail_subject'], $data))
            ->greeting(filled($data['name'] ?? null) ? __('Hi :name,', ['name' => $data['name']]) : __('Hello,'))
            ->salutation(__('— The :app team', ['app' => $data['app_name'] ?? app(BrandingSettings::class)->appName()]));

        // The button goes after the first paragraph, so the opening line explains what it is for.
        foreach ($paragraphs as $index => $paragraph) {
            $message->line($paragraph);

            if ($index === 0 && $event->actionLabel !== null && filled($data['link'] ?? null)) {
                $message->action($settings->render($event->actionLabel, $data), (string) $data['link']);
            }
        }

        return $message;
    }

    /**
     * Add the placeholders every event provides, plus `{time}` for events that report when something happened.
     *
     * @param  array<string, scalar|null>  $data
     * @return array<string, scalar|null>
     */
    public static function withCommonData(array $data, object $notifiable): array
    {
        return [
            'name' => $notifiable instanceof User ? $notifiable->display_name : null,
            'app_name' => app(BrandingSettings::class)->publicDetails()['name'],
            'time' => now()->format('j M Y, g:i A T'),
            ...$data,
        ];
    }

    protected function definition(): NotificationEvent
    {
        return app(NotificationCatalog::class)->find($this->event);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function dataFor(object $notifiable): array
    {
        return self::withCommonData($this->data, $notifiable);
    }
}
