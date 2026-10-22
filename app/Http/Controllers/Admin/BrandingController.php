<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BrandingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    public function edit(BrandingSettings $branding): Response
    {
        return Inertia::render('Admin/Branding', [
            'settings' => $branding->publicDetails(),
            'aboutPageVisible' => $branding->aboutPageVisible(),
            'creditVisible' => $branding->creditVisible(),
            'updateUrl' => route('admin.branding.update'),
        ]);
    }

    public function update(Request $request, BrandingSettings $branding): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'organisation_name' => ['nullable', 'string', 'max:120'],
            'service_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'version' => ['required', 'string', 'max:32', 'regex:~\A[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?(?:\+[a-zA-Z0-9.-]+)?\z~'],
            'launched_on' => ['nullable', 'date_format:Y-m-d'],
            'remove_logo' => ['required', 'boolean'],
            'about_page_visible' => ['sometimes', 'boolean'],
            'boilerplate_credit_visible' => ['sometimes', 'boolean'],
            'logo' => ['nullable', Rule::prohibitedIf($request->boolean('remove_logo')), 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ], [
            'version.regex' => 'Enter a version such as 1.0.0 or 1.0.0-beta.1.',
        ]);

        $branding->update(
            $validated['name'],
            $validated['version'],
            $validated['launched_on'] ?? null,
            $request->file('logo'),
            (bool) $validated['remove_logo'],
            array_key_exists('about_page_visible', $validated) ? (bool) $validated['about_page_visible'] : null,
            array_key_exists('boilerplate_credit_visible', $validated) ? (bool) $validated['boilerplate_credit_visible'] : null,
            $validated['organisation_name'] ?? null,
            $validated['service_name'] ?? null,
            $validated['contact_email'] ?? null,
        );

        return back()->with('status', __('Branding updated.'));
    }
}
