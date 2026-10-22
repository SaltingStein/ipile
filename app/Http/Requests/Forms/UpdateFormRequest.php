<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormLayout;
use App\Services\Forms\FormStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What the form builder saves: the form's settings and its ordered list of fields.
 *
 * @phpstan-import-type FormField from Form
 */
class UpdateFormRequest extends FormRequest
{
    public const MAX_FIELDS = 60;

    public const MAX_OPTIONS = 50;

    /**
     * Team managers for team forms, `manage_forms` for site forms (see FormPolicy).
     */
    public function authorize(): bool
    {
        Gate::authorize('update', $this->editedForm());

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $form = $this->editedForm();
        $isRegistration = $form->isRegistrationForm();

        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', $isRegistration
                ? Rule::enum(FormStatus::class)->only([FormStatus::Draft, FormStatus::Published])
                : Rule::enum(FormStatus::class)],
            'slug' => $isRegistration ? ['exclude'] : [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Form::class, 'slug')->ignore($form),
            ],
            'submit_label' => $isRegistration ? ['exclude'] : ['nullable', 'string', 'max:60'],
            'success_message' => $isRegistration ? ['exclude'] : ['nullable', 'string', 'max:1000'],
            'max_submissions' => $isRegistration ? ['exclude'] : ['nullable', 'integer', 'min:1', 'max:1000000'],
            'closes_at' => $isRegistration ? ['exclude'] : ['nullable', 'date'],
            'sends_confirmation' => $isRegistration ? ['exclude'] : ['boolean'],
            'layout' => $isRegistration ? ['exclude'] : ['sometimes', Rule::enum(FormLayout::class)],

            'fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*' => ['array:id,key,type,label,hint,placeholder,required,options,min,max'],
            'fields.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.type' => ['required', Rule::enum(FormFieldType::class)],
            'fields.*.label' => ['required', 'string', 'max:200'],
            'fields.*.hint' => ['nullable', 'string', 'max:1000'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:120'],
            'fields.*.required' => ['boolean'],
            'fields.*.options' => ['present', 'array', 'max:'.self::MAX_OPTIONS],
            'fields.*.options.*' => ['required', 'string', 'max:120'],
            'fields.*.min' => ['nullable', 'integer', 'between:-1000000000,1000000000'],
            'fields.*.max' => ['nullable', 'integer', 'between:-1000000000,1000000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes, for example lagos-tech-summit.',
            'slug.unique' => 'Another form already uses this address.',
            'fields.*.key.regex' => 'Start with a letter and use only lowercase letters, numbers and underscores.',
            'fields.*.key.distinct' => 'Each field needs a different field ID.',
            'fields.*.label.required' => 'Give this field a label.',
            'fields.*.options.*.required' => 'Options cannot be empty.',
        ];
    }

    /**
     * Checks that span several inputs: choice fields need options, limits must be in order, and a published
     * form needs at least one question.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fields = $this->input('fields', []);

            foreach ($fields as $index => $field) {
                $type = FormFieldType::from($field['type']);
                $options = array_map(fn (string $option) => mb_strtolower(trim($option)), $field['options'] ?? []);

                if ($type->hasOptions() && $options === []) {
                    $validator->errors()->add("fields.{$index}.options", __('Add at least one option.'));
                }

                if ($type->hasOptions() && count($options) !== count(array_unique($options))) {
                    $validator->errors()->add("fields.{$index}.options", __('Each option must be different.'));
                }

                if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
                    $validator->errors()->add("fields.{$index}.max", __('The maximum must be at least the minimum.'));
                }
            }

            $collectsInput = collect($fields)->contains(fn (array $field) => FormFieldType::from($field['type'])->collectsInput());

            if ($this->input('status') === FormStatus::Published->value && ! $collectsInput) {
                $validator->errors()->add('fields', __('Add at least one question before publishing.'));
            }

            $hasEmailField = collect($fields)->contains('type', FormFieldType::Email->value);

            if ($this->boolean('sends_confirmation') && ! $this->editedForm()->isRegistrationForm() && ! $hasEmailField) {
                $validator->errors()->add('sends_confirmation', __('Add an Email field so there is an address to send the confirmation to.'));
            }
        }];
    }

    /**
     * The validated fields in a consistent shape: settings that do not apply to a type are cleared.
     *
     * @return list<FormField>
     */
    public function fields(): array
    {
        return array_map(function (array $field) {
            $type = FormFieldType::from($field['type']);
            $hasLimits = in_array($type, [FormFieldType::ShortText, FormFieldType::LongText, FormFieldType::Number], true);
            $hasPlaceholder = in_array($type, [FormFieldType::ShortText, FormFieldType::LongText, FormFieldType::Email, FormFieldType::Phone, FormFieldType::Number, FormFieldType::Url], true);

            return [
                'id' => $field['id'],
                'key' => $field['key'],
                'type' => $type->value,
                'label' => trim($field['label']),
                'hint' => filled($field['hint'] ?? null) ? trim($field['hint']) : null,
                'placeholder' => $hasPlaceholder && filled($field['placeholder'] ?? null) ? trim($field['placeholder']) : null,
                'required' => $type->collectsInput() && (bool) ($field['required'] ?? false),
                'options' => $type->hasOptions() ? array_values(array_map('trim', $field['options'])) : [],
                'min' => $hasLimits && isset($field['min']) ? (int) $field['min'] : null,
                'max' => $hasLimits && isset($field['max']) ? (int) $field['max'] : null,
            ];
        }, array_values($this->validated('fields')));
    }

    /**
     * The form's settings, without the fields.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return collect($this->validated())->except('fields')->all();
    }

    private function editedForm(): Form
    {
        return $this->route('form');
    }
}
