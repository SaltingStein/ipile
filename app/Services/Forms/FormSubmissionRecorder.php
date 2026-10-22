<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\Notifications\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves one set of answers. The form row is locked while its status and response limit are checked, so two
 * people sending the last place at the same moment cannot both get in.
 */
class FormSubmissionRecorder
{
    public function __construct(private FormSchema $schema) {}

    /**
     * @param  array<string, mixed>  $answers  validated `answers`
     *
     * @throws ValidationException under `form` when the form is not taking answers
     */
    public function record(Form $form, array $answers, ?User $user): FormSubmission
    {
        $submission = DB::transaction(function () use ($form, $answers, $user) {
            $lockedForm = Form::query()->whereKey($form->getKey())->lockForUpdate()->firstOrFail();

            if (($reason = $lockedForm->closedReason()) !== null) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $submission = $lockedForm->submissions()->make([
                'data' => $this->schema->normalise($lockedForm->fields, $answers),
            ]);
            $submission->user()->associate($user);
            $submission->save();

            return $submission;
        });

        if ($form->sends_confirmation) {
            $this->sendConfirmation($form, $submission);
        }

        return $submission;
    }

    /**
     * Email the address given in the form's first Email field. The message only contains the form title and
     * the form's own thank-you text, never the answers, so the form cannot be used to send arbitrary text.
     */
    private function sendConfirmation(Form $form, FormSubmission $submission): void
    {
        $emailField = collect($form->inputFields())->firstWhere('type', FormFieldType::Email->value);
        $email = $emailField === null ? null : ($submission->data[$emailField['key']] ?? null);

        if (! is_string($email) || $email === '') {
            return;
        }

        Notify::event('forms.submission_received')
            ->toEmail($email)
            ->with([
                'form' => $form->title,
                'message' => $form->success_message ?: __('Thank you, we have received your response.'),
            ])
            ->send();
    }
}
