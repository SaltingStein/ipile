<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Notify;
use App\Services\Sms\SmsSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Notifications: which channels each event uses and what the messages say.
 */
class NotificationSettingsController extends Controller
{
    public const SMS_MAX_LENGTH = 640;

    public function __construct(
        private NotificationCatalog $catalog,
        private NotificationSettings $settings,
    ) {}

    public function index(SmsSender $sms): Response
    {
        $events = collect($this->catalog->all())->map(fn (NotificationEvent $event): array => [
            'key' => $event->key,
            'group' => $event->group,
            'label' => $event->label,
            'description' => $event->description,
            'available_channels' => $event->channels,
            'required' => $event->required,
            'has_action' => $event->actionLabel !== null,
            'placeholders' => array_keys($event->allPlaceholders()),
            ...$this->settings->config($event),
        ])->values();

        return Inertia::render('Admin/Notifications', [
            'events' => $events,
            'smsEnabled' => $sms->isEnabled(),
            'smsMaxLength' => self::SMS_MAX_LENGTH,
            'smsSetupUrl' => route('admin.integrations.show', 'smpp'),
        ]);
    }

    public function update(Request $request, string $event): RedirectResponse
    {
        $definition = $this->definition($event);

        $validated = $request->validate([
            'channels' => ['present', 'array'],
            'channels.*' => ['string', Rule::in($definition->channels)],
            'mail_subject' => ['required', 'string', 'max:150'],
            'mail_body' => ['required', 'string', 'max:5000'],
            'sms_body' => [Rule::requiredIf($definition->supports(NotificationEvent::SMS)), 'nullable', 'string', 'max:'.self::SMS_MAX_LENGTH],
        ], [], [
            'mail_subject' => 'email subject',
            'mail_body' => 'email message',
            'sms_body' => 'SMS message',
        ]);

        validator($validated)->after(function (Validator $validator) use ($definition, $validated): void {
            foreach (['mail_subject', 'mail_body', 'sms_body'] as $field) {
                $unknown = $this->settings->unknownPlaceholders($definition, $validated[$field] ?? null);

                if ($unknown !== []) {
                    $validator->errors()->add($field, __('Unknown placeholder: :list. Use only the placeholders listed for this event.', [
                        'list' => collect($unknown)->map(fn (string $name) => '{'.$name.'}')->join(', '),
                    ]));
                }
            }
        })->validate();

        $this->settings->update($definition, $validated);

        return back()->with('status', __(':event notification saved.', ['event' => $definition->label]));
    }

    /**
     * Restore the default channels and wording.
     */
    public function destroy(string $event): RedirectResponse
    {
        $definition = $this->definition($event);

        $this->settings->reset($definition);

        return back()->with('status', __(':event notification reset to the default.', ['event' => $definition->label]));
    }

    /**
     * Send the saved version to the signed-in administrator, filled with example values.
     */
    public function test(Request $request, string $event): RedirectResponse
    {
        $definition = $this->definition($event);

        Notify::event($definition->key)
            ->to($request->user())
            ->with($definition->placeholders)
            ->send();

        return back()->with('status', __('Test :event notification sent to your account on the switched-on channels.', ['event' => $definition->label]));
    }

    private function definition(string $event): NotificationEvent
    {
        abort_unless($this->catalog->has($event), 404);

        return $this->catalog->find($event);
    }
}
