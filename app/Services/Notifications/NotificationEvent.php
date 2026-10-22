<?php

namespace App\Services\Notifications;

/**
 * Something the app tells people about (welcome, password changed, payment received…), with its default
 * channels and wording. Administrators can change both in Admin → Notifications.
 *
 * Templates use `{placeholder}` tokens. Every event also gets `{name}` and `{app_name}`.
 */
final readonly class NotificationEvent
{
    public const MAIL = 'mail';

    public const SMS = 'sms';

    /**
     * @param  list<'mail'|'sms'>  $channels  Channels this event can be sent on.
     * @param  list<'mail'|'sms'>  $defaultChannels  Channels that are on until an administrator changes them.
     * @param  array<string, string>  $placeholders  Event-specific placeholder => example value (used for test sends).
     * @param  string|null  $actionLabel  Email button text. The button opens the `{link}` placeholder.
     * @param  bool  $required  Email cannot be switched off (the user needs it to use their account).
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $description,
        public array $channels,
        public array $defaultChannels,
        public array $placeholders,
        public string $mailSubject,
        public string $mailBody,
        public ?string $smsBody = null,
        public ?string $actionLabel = null,
        public bool $required = false,
    ) {}

    public function supports(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /**
     * Placeholders available in this event's templates, with example values.
     *
     * @return array<string, string>
     */
    public function allPlaceholders(): array
    {
        return ['name' => 'Ada', 'app_name' => 'Ìpìlẹ̀', ...$this->placeholders];
    }
}
