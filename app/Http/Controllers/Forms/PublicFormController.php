<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\Forms\FormMedia;
use App\Services\Forms\FormSchema;
use App\Services\Forms\FormStatus;
use App\Services\Forms\FormSubmissionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A published form at /f/{slug}, open to anyone, signed in or not. Drafts are not found.
 */
class PublicFormController extends Controller
{
    /**
     * Hidden input real people never fill in. Bots that fill every input are thanked and ignored.
     */
    public const HONEYPOT = 'hp_check';

    public function show(Request $request, Form $form, FormMedia $media): Response
    {
        abort_if($form->status === FormStatus::Draft, 404);

        $form->loadCount('submissions');
        $submitted = $request->session()->get('form_submitted') === $form->id;

        return Inertia::render('Forms/Show', [
            'form' => [
                'title' => $form->title,
                'description' => $form->description,
                'fields' => $form->fields,
                'submit_label' => $form->submit_label ?: __('Submit'),
                'submit_url' => route('forms.public.store', $form->slug),
                'layout' => $form->layout->value,
                'media' => $media->details($form),
                'closes_at' => $form->closes_at?->toIso8601String(),
                'places_left' => $form->max_submissions === null ? null : max($form->max_submissions - $form->submissions_count, 0),
            ],
            'closedReason' => $submitted ? null : $form->closedReason(),
            'successMessage' => $submitted ? ($form->success_message ?: __('Thank you, we have received your response.')) : null,
            'honeypot' => self::HONEYPOT,
        ]);
    }

    public function store(Request $request, Form $form, FormSchema $schema, FormSubmissionRecorder $recorder): RedirectResponse
    {
        abort_if($form->status === FormStatus::Draft, 404);

        if (filled($request->input(self::HONEYPOT))) {
            return $this->thankYou($form);
        }

        $validated = $request->validate($schema->rules($form->fields), [], $schema->attributes($form->fields));
        $recorder->record($form, $validated['answers'] ?? [], $request->user());

        return $this->thankYou($form);
    }

    private function thankYou(Form $form): RedirectResponse
    {
        return redirect()->route('forms.public.show', $form->slug)->with('form_submitted', $form->id);
    }
}
