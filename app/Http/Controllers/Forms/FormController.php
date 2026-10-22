<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateFormRequest;
use App\Http\Resources\FormResource;
use App\Models\Form;
use App\Models\Team;
use App\Services\Forms\FormCreator;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormLayout;
use App\Services\Forms\FormMedia;
use App\Services\Forms\FormStatus;
use App\Services\Forms\FormTemplates;
use App\Services\NavigationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current team's forms, and the drag-and-drop builder shared by team and site forms. Who may edit a
 * form is decided by FormPolicy.
 */
class FormController extends Controller
{
    /**
     * List the current team's forms. Members see them; the owner and admins can also create and edit.
     */
    public function index(Request $request, FormTemplates $templates): Response
    {
        $team = $this->currentTeam($request);
        $forms = $team?->forms()->withCount('submissions')->latest('updated_at')->get() ?? collect();

        return Inertia::render('Forms/Index', [
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name],
            'canManage' => $team !== null && Gate::allows('manageForms', $team),
            'forms' => FormResource::collection($forms)->resolve($request),
            'templates' => $templates->options(),
        ]);
    }

    /**
     * Create a draft form for the current team from a template and open it in the builder.
     */
    public function store(Request $request, FormTemplates $templates, FormCreator $creator): RedirectResponse
    {
        $team = $this->currentTeam($request);
        abort_if($team === null, 404);
        Gate::authorize('manageForms', $team);

        $validated = $request->validate(['template' => ['required', Rule::in(array_keys($templates->all()))]]);
        $form = $creator->create($team, $request->user(), $validated['template']);

        return redirect()->route('forms.edit', $form)->with('status', __('Form created. Add or drag in fields, then publish it.'));
    }

    /**
     * The builder.
     */
    public function edit(Request $request, Form $form, FormMedia $media, NavigationSettings $navigation): Response
    {
        Gate::authorize('update', $form);

        $form->load('team')->loadCount('submissions');
        $path = $form->slug === null ? null : '/f/'.$form->slug;

        return Inertia::render('Forms/Builder', [
            'form' => (new FormResource($form))->withFields()->resolve($request),
            'fieldTypes' => FormFieldType::options(),
            'statuses' => FormStatus::options(),
            'layouts' => FormLayout::options(),
            'mediaLimits' => $media->limits(),
            // Whether the form is already in each menu; null when this person cannot change menus.
            'menus' => $path !== null && $request->user()->can('manage_settings') ? [
                'path' => $path,
                'site' => $navigation->contains(NavigationSettings::MENU_SITE, $path),
                'workspace' => $navigation->contains(NavigationSettings::MENU_WORKSPACE, $path),
            ] : null,
            'backUrl' => $form->isSiteForm() ? route('admin.forms.index') : route('forms.index'),
        ]);
    }

    /**
     * Save the builder: settings and the ordered fields.
     */
    public function update(UpdateFormRequest $request, Form $form): RedirectResponse
    {
        $form->fill([...$request->settings(), 'fields' => $request->fields()])->save();

        return back()->with('status', match ($form->status) {
            FormStatus::Published => $form->isRegistrationForm()
                ? __('Saved. These questions now appear on the sign-up page.')
                : __('Saved. The form is live.'),
            FormStatus::Closed => __('Saved. The form is closed to new responses.'),
            FormStatus::Draft => __('Draft saved.'),
        });
    }

    /**
     * Open a new draft copied from a form this person may manage.
     */
    public function duplicate(Request $request, Form $form, FormCreator $creator): RedirectResponse
    {
        Gate::authorize('update', $form);

        $copy = $creator->duplicate($form, $request->user());

        return redirect()->route('forms.edit', $copy)->with('status', __('Copied as a new draft. Review the settings before publishing.'));
    }

    /**
     * Delete the form and all of its responses. The sign-up form is never deleted.
     */
    public function destroy(Form $form, FormMedia $media): RedirectResponse
    {
        // Checked here as well as in FormPolicy because super admins pass every policy.
        abort_if($form->isRegistrationForm(), 403, __('The sign-up form cannot be deleted.'));
        Gate::authorize('delete', $form);

        $form->delete();
        $media->deleteFile($form->media_path);

        return redirect()->to($form->isSiteForm() ? route('admin.forms.index') : route('forms.index'))
            ->with('status', __('Form deleted.'));
    }

    /**
     * The user's current team, or null when there is none or they are no longer on it.
     */
    private function currentTeam(Request $request): ?Team
    {
        $team = $request->user()->currentTeam;

        return $team !== null && Gate::allows('view', $team) ? $team : null;
    }
}
