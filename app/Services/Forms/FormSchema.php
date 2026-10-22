<?php

namespace App\Services\Forms;

use App\Models\Form;
use Illuminate\Validation\Rule;

/**
 * Turns a form's saved fields into validation rules for the answers people send, and tidies the validated
 * answers for storage. Answers are sent under `answers.{key}`, so field keys never clash with other inputs
 * (such as the sign-up form's name, email and password).
 *
 * @phpstan-import-type FormField from Form
 */
class FormSchema
{
    /**
     * Phone numbers: digits with optional +, spaces, brackets and dashes (e.g. 0803 123 4567, +234 803 123 4567).
     */
    public const PHONE_PATTERN = '/^\+?[0-9][0-9 ()\-]{6,19}$/';

    /**
     * @param  list<FormField>  $fields
     * @return array<string, list<mixed>>
     */
    public function rules(array $fields): array
    {
        $rules = ['answers' => ['sometimes', 'array']];

        foreach ($this->inputFields($fields) as $field) {
            $attribute = 'answers.'.$field['key'];
            $presence = $field['required'] ? 'required' : 'nullable';
            $options = $field['options'] ?? [];

            $rules[$attribute] = match (FormFieldType::from($field['type'])) {
                FormFieldType::ShortText => [$presence, 'string', ...$this->lengthRules($field, 255)],
                FormFieldType::LongText => [$presence, 'string', ...$this->lengthRules($field, 5000)],
                FormFieldType::Email => [$presence, 'string', 'email', 'max:255'],
                FormFieldType::Phone => [$presence, 'string', 'regex:'.self::PHONE_PATTERN],
                FormFieldType::Number => [$presence, 'numeric', ...$this->rangeRules($field)],
                FormFieldType::Date => [$presence, 'date_format:Y-m-d'],
                FormFieldType::Url => [$presence, 'string', 'url:http,https', 'max:2048'],
                FormFieldType::Dropdown, FormFieldType::SingleChoice => [$presence, 'string', Rule::in($options)],
                FormFieldType::MultipleChoice => [$presence, 'array', 'max:'.max(count($options), 1)],
                FormFieldType::Checkbox => $field['required'] ? ['accepted'] : ['nullable', 'boolean'],
                FormFieldType::Heading => [],
            };

            if ($field['type'] === FormFieldType::MultipleChoice->value) {
                $rules[$attribute.'.*'] = ['string', 'distinct', Rule::in($options)];
            }
        }

        return $rules;
    }

    /**
     * Field labels for validation messages ("The Company field is required.").
     *
     * @param  list<FormField>  $fields
     * @return array<string, string>
     */
    public function attributes(array $fields): array
    {
        $attributes = [];

        foreach ($this->inputFields($fields) as $field) {
            $attributes['answers.'.$field['key']] = $field['label'];
            $attributes['answers.'.$field['key'].'.*'] = $field['label'];
        }

        return $attributes;
    }

    /**
     * Keep only answers to the form's own fields, in a consistent shape per type.
     *
     * @param  list<FormField>  $fields
     * @param  array<string, mixed>  $answers  validated `answers`
     * @return array<string, string|int|float|bool|list<string>|null>
     */
    public function normalise(array $fields, array $answers): array
    {
        $normalised = [];

        foreach ($this->inputFields($fields) as $field) {
            $value = $answers[$field['key']] ?? null;

            $normalised[$field['key']] = match (FormFieldType::from($field['type'])) {
                FormFieldType::Checkbox => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                FormFieldType::MultipleChoice => array_values(array_map('strval', (array) ($value ?? []))),
                FormFieldType::Number => is_numeric($value) ? $value + 0 : null,
                default => $value === null || $value === '' ? null : (string) $value,
            };
        }

        return $normalised;
    }

    /**
     * An answer as plain text, for exports and emails.
     */
    public function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }

    /**
     * @param  list<FormField>  $fields
     * @return list<FormField>
     */
    public function inputFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field) => FormFieldType::tryFrom($field['type'] ?? '')?->collectsInput() ?? false,
        ));
    }

    /**
     * @param  FormField  $field
     * @return list<string>
     */
    private function lengthRules(array $field, int $defaultMax): array
    {
        return array_values(array_filter([
            $field['min'] !== null ? 'min:'.$field['min'] : null,
            'max:'.min($field['max'] ?? $defaultMax, $defaultMax),
        ]));
    }

    /**
     * @param  FormField  $field
     * @return list<string>
     */
    private function rangeRules(array $field): array
    {
        return array_values(array_filter([
            $field['min'] !== null ? 'min:'.$field['min'] : null,
            $field['max'] !== null ? 'max:'.$field['max'] : null,
        ]));
    }
}
