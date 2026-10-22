<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormResource;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormSubmissionExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A form's responses: read, download as CSV, delete. Same access as editing the form (FormPolicy).
 */
class FormSubmissionController extends Controller
{
    public const PER_PAGE = 25;

    /**
     * Newest responses first, one column per field.
     */
    public function index(Request $request, Form $form): Response
    {
        Gate::authorize('update', $form);

        $form->load('team')->loadCount('submissions');
        $submissions = $form->submissions()->with('user:id,name,email')->latest()->latest('id')->paginate(self::PER_PAGE);

        $columns = collect($form->inputFields())
            ->map(fn (array $field) => ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'removed' => false])
            ->keyBy('key');

        // Answers to fields removed from the form since are kept and shown at the end.
        foreach ($submissions->items() as $submission) {
            foreach (array_keys($submission->data ?? []) as $key) {
                $columns[$key] ??= ['key' => $key, 'label' => $key, 'type' => FormFieldType::ShortText->value, 'removed' => true];
            }
        }

        return Inertia::render('Forms/Submissions', [
            'form' => (new FormResource($form))->resolve($request),
            'columns' => $columns->values(),
            'submissions' => $submissions->through(fn (FormSubmission $submission) => [
                'id' => $submission->id,
                'submitted_at' => $submission->created_at?->toIso8601String(),
                'user' => $submission->user === null ? null : ['name' => $submission->user->name, 'email' => $submission->user->email],
                'data' => $submission->data,
            ]),
            'builderUrl' => route('forms.edit', $form),
            'backUrl' => $form->isSiteForm() ? route('admin.forms.index') : route('forms.index'),
        ]);
    }

    /**
     * Every response as a CSV file.
     */
    public function export(Form $form, FormSubmissionExport $export): StreamedResponse
    {
        Gate::authorize('update', $form);

        return $export->download($form);
    }

    /**
     * Delete one response, e.g. a test entry or a cancelled registration (frees a place when there is a limit).
     */
    public function destroy(Form $form, FormSubmission $submission): RedirectResponse
    {
        Gate::authorize('update', $form);

        $submission->delete();

        return back()->with('status', __('Response deleted.'));
    }
}
