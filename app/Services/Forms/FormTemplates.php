<?php

namespace App\Services\Forms;

use App\Models\Form;
use Illuminate\Support\Str;

/**
 * Ready-made starting points for new forms, so a team can publish an event registration in minutes and
 * adjust it in the builder. Add your own by adding an entry to {@see self::all()}.
 *
 * @phpstan-import-type FormField from Form
 *
 * @phpstan-type FormTemplate array{label: string, description: string, title: string, form_description: string|null, fields: list<FormField>}
 */
class FormTemplates
{
    public const BLANK = 'blank';

    /**
     * @return array<string, FormTemplate>
     */
    public function all(): array
    {
        return [
            self::BLANK => [
                'label' => 'Blank form',
                'description' => 'Start from nothing and add your own questions.',
                'title' => 'Untitled form',
                'form_description' => null,
                'fields' => [],
            ],
            'event_registration' => [
                'label' => 'Event registration',
                'description' => 'Attendee details, sessions, dietary needs and a code of conduct.',
                'title' => 'Event registration',
                'form_description' => 'Tell us a little about yourself to reserve your place.',
                'fields' => [
                    $this->field(FormFieldType::Heading, 'about_you', 'About you'),
                    $this->field(FormFieldType::ShortText, 'full_name', 'Full name', required: true),
                    $this->field(FormFieldType::Email, 'email', 'Email', required: true, hint: 'We will send your confirmation here.'),
                    $this->field(FormFieldType::Phone, 'phone', 'Phone number'),
                    $this->field(FormFieldType::ShortText, 'organisation', 'Organisation'),
                    $this->field(FormFieldType::ShortText, 'job_title', 'Job title'),
                    $this->field(FormFieldType::Heading, 'attendance', 'Your attendance'),
                    $this->field(FormFieldType::MultipleChoice, 'sessions', 'Which days will you attend?', required: true, options: ['Day 1', 'Day 2', 'Workshops']),
                    $this->field(FormFieldType::SingleChoice, 'dietary_needs', 'Dietary requirements', options: ['None', 'Vegetarian', 'Vegan', 'Halal', 'Other']),
                    $this->field(FormFieldType::LongText, 'accessibility', 'Accessibility needs', hint: 'Anything we should arrange so you can take part fully.'),
                    $this->field(FormFieldType::Dropdown, 'referral', 'How did you hear about us?', options: ['Social media', 'A friend or colleague', 'Email', 'Other']),
                    $this->field(FormFieldType::Checkbox, 'code_of_conduct', 'I agree to follow the event code of conduct', required: true),
                ],
            ],
            'contact' => [
                'label' => 'Contact us',
                'description' => 'Name, email and a message.',
                'title' => 'Contact us',
                'form_description' => 'Send us a message and we will get back to you.',
                'fields' => [
                    $this->field(FormFieldType::ShortText, 'full_name', 'Full name', required: true),
                    $this->field(FormFieldType::Email, 'email', 'Email', required: true),
                    $this->field(FormFieldType::ShortText, 'subject', 'Subject'),
                    $this->field(FormFieldType::LongText, 'message', 'Message', required: true),
                ],
            ],
            'feedback' => [
                'label' => 'Feedback survey',
                'description' => 'A rating, what went well and what to improve.',
                'title' => 'Tell us what you think',
                'form_description' => null,
                'fields' => [
                    $this->field(FormFieldType::SingleChoice, 'rating', 'Overall, how was it?', required: true, options: ['Excellent', 'Good', 'Fair', 'Poor']),
                    $this->field(FormFieldType::LongText, 'highlights', 'What did you like most?'),
                    $this->field(FormFieldType::LongText, 'improvements', 'What could we do better?'),
                    $this->field(FormFieldType::SingleChoice, 'recommend', 'Would you recommend us to a friend?', options: ['Yes', 'Maybe', 'No']),
                    $this->field(FormFieldType::Email, 'email', 'Email (optional)', hint: 'Only if you would like a reply.'),
                ],
            ],
        ];
    }

    /**
     * @return FormTemplate|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Choices for the "new form" picker.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function options(): array
    {
        return collect($this->all())
            ->map(fn (array $template, string $key) => ['value' => $key, 'label' => $template['label'], 'description' => $template['description']])
            ->values()
            ->all();
    }

    /**
     * A new field definition with a fresh id.
     *
     * @param  list<string>  $options
     * @return FormField
     */
    public static function field(FormFieldType $type, string $key, string $label, bool $required = false, array $options = [], ?string $hint = null): array
    {
        return [
            'id' => 'f_'.Str::lower(Str::random(10)),
            'key' => $key,
            'type' => $type->value,
            'label' => $label,
            'hint' => $hint,
            'placeholder' => null,
            'required' => $required && $type->collectsInput(),
            'options' => $type->hasOptions() ? $options : [],
            'min' => null,
            'max' => null,
        ];
    }
}
