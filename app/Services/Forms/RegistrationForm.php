<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The extra questions on the sign-up page, built in Admin → Forms. Name, email and password are always
 * asked by the registration form itself; these fields are added below them once the form is published,
 * on both the web page and `POST /api/v1/auth/register` (sent as `answers.{key}`).
 *
 * @phpstan-import-type FormField from Form
 */
class RegistrationForm
{
    public const PURPOSE = 'registration';

    public function __construct(
        private FormSchema $schema,
        private FormSubmissionRecorder $recorder,
    ) {}

    /**
     * The sign-up form, created as an empty draft the first time an admin opens the forms list.
     */
    public function form(): Form
    {
        $existing = Form::query()->where('purpose', self::PURPOSE)->first();

        if ($existing !== null) {
            return $existing;
        }

        // `purpose` is not mass assignable, so a request can never turn another form into the sign-up form.
        $form = new Form(['title' => __('Sign-up questions'), 'status' => FormStatus::Draft, 'fields' => []]);
        $form->purpose = self::PURPOSE;

        try {
            $form->save();
        } catch (UniqueConstraintViolationException) {
            // Another admin opened the forms list at the same moment and created it first.
            return Form::query()->where('purpose', self::PURPOSE)->firstOrFail();
        }

        return $form;
    }

    /**
     * The fields to show on the sign-up page: empty until the form is published.
     *
     * @return list<FormField>
     */
    public function fields(): array
    {
        return $this->publishedForm()?->fields ?? [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $fields = $this->fields();

        return $fields === [] ? [] : $this->schema->rules($fields);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->schema->attributes($this->fields());
    }

    /**
     * Store the new account's answers. Does nothing while the form is a draft or has no questions.
     *
     * @param  array<string, mixed>  $answers  validated `answers`
     */
    public function record(User $user, array $answers): void
    {
        $form = $this->publishedForm();

        if ($form !== null && $form->inputFields() !== []) {
            $this->recorder->record($form, $answers, $user);
        }
    }

    private function publishedForm(): ?Form
    {
        return Form::query()
            ->where('purpose', self::PURPOSE)
            ->where('status', FormStatus::Published)
            ->first();
    }
}
