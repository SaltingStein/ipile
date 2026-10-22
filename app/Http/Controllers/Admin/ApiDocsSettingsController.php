<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiDocs\ApiDocsSettings;
use App\Services\ApiDocs\ApiDocsVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → API documentation: who may open the auto-generated docs at /docs/api.
 */
class ApiDocsSettingsController extends Controller
{
    public function edit(ApiDocsSettings $settings): Response
    {
        return Inertia::render('Admin/ApiDocs', [
            'visibility' => $settings->visibility()->value,
            'options' => array_map(fn (ApiDocsVisibility $option) => [
                'value' => $option->value,
                'label' => $option->label(),
                'description' => $option->description(),
            ], ApiDocsVisibility::cases()),
            'portalUrl' => route('developers'),
            'docsUrl' => route('scramble.docs.ui'),
            'specUrl' => route('scramble.docs.document'),
            'updateUrl' => route('admin.api-docs.update'),
        ]);
    }

    public function update(Request $request, ApiDocsSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'visibility' => ['required', 'string', Rule::enum(ApiDocsVisibility::class)],
        ]);

        $settings->update(ApiDocsVisibility::from($validated['visibility']));

        return back()->with('status', __('API documentation settings saved.'));
    }
}
