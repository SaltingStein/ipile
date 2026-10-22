<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormResource;
use App\Models\Form;
use App\Services\Forms\FormCreator;
use App\Services\Forms\FormTemplates;
use App\Services\Forms\RegistrationForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Forms: the site's own forms (contact, waitlist…) and the extra sign-up questions. Editing uses the
 * same builder as team forms; routes require `manage_forms`.
 */
class SiteFormController extends Controller
{
    public function index(Request $request, FormTemplates $templates, RegistrationForm $registrationForm): Response
    {
        $registrationForm->form();

        $forms = Form::query()->forSite()->withCount('submissions')
            ->orderByRaw('purpose is null')
            ->latest('updated_at')
            ->get();

        return Inertia::render('Admin/Forms', [
            'forms' => FormResource::collection($forms)->resolve($request),
            'templates' => $templates->options(),
        ]);
    }

    public function store(Request $request, FormTemplates $templates, FormCreator $creator): RedirectResponse
    {
        $validated = $request->validate(['template' => ['required', Rule::in(array_keys($templates->all()))]]);
        $form = $creator->create(null, $request->user(), $validated['template']);

        return redirect()->route('forms.edit', $form)->with('status', __('Form created. Add or drag in fields, then publish it.'));
    }
}
