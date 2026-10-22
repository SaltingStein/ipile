<?php

namespace App\Services\Notifications;

use App\Models\Setting;

/**
 * Per-event channels and templates saved from Admin → Notifications, falling back to the catalog defaults.
 */
class NotificationSettings
{
    public const KEY_PREFIX = 'notifications.';

    /**
     * @return array{channels: list<string>, mail_subject: string, mail_body: string, sms_body: ?string, customised: bool}
     */
    public function config(NotificationEvent $event): array
    {
        $stored = json_decode(Setting::valueOf(self::KEY_PREFIX.$event->key, 'null'), true);
        $stored = is_array($stored) ? $stored : [];

        $channels = array_values(array_intersect($event->channels, $stored['channels'] ?? $event->defaultChannels));

        if ($event->required && ! in_array(NotificationEvent::MAIL, $channels, true)) {
            array_unshift($channels, NotificationEvent::MAIL);
        }

        return [
            'channels' => $channels,
            'mail_subject' => $stored['mail_subject'] ?? $event->mailSubject,
            'mail_body' => $stored['mail_body'] ?? $event->mailBody,
            'sms_body' => $event->supports(NotificationEvent::SMS) ? ($stored['sms_body'] ?? $event->smsBody) : null,
            'customised' => $stored !== [],
        ];
    }

    /**
     * @param  array{channels: list<string>, mail_subject: string, mail_body: string, sms_body?: ?string}  $config
     */
    public function update(NotificationEvent $event, array $config): void
    {
        Setting::store(self::KEY_PREFIX.$event->key, json_encode([
            'channels' => array_values(array_intersect($event->channels, $config['channels'])),
            'mail_subject' => $config['mail_subject'],
            'mail_body' => $config['mail_body'],
            'sms_body' => $event->supports(NotificationEvent::SMS) ? ($config['sms_body'] ?? null) : null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Go back to the default channels and wording.
     */
    public function reset(NotificationEvent $event): void
    {
        Setting::query()->where('key', self::KEY_PREFIX.$event->key)->first()?->delete();
    }

    /**
     * Placeholders used in a template that the event does not provide, e.g. a typo such as `{nmae}`.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(NotificationEvent $event, ?string $template): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', (string) $template, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys($event->allPlaceholders()))));
    }

    /**
     * Replace `{placeholder}` tokens. Unknown tokens are left as they are so mistakes are visible.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function render(string $template, array $data): string
    {
        $replacements = [];

        foreach ($data as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
