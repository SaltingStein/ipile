<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarketingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingSettingsController extends Controller
{
    public function edit(MarketingSettings $settings): Response
    {
        return Inertia::render('Admin/Marketing', [
            'enabled' => $settings->enabled(),
            'repositoryUrl' => $settings->repositoryUrl(),
            'updateUrl' => route('admin.marketing.update'),
            'homeUrl' => route('home'),
            'marketingUrl' => route('marketing'),
        ]);
    }

    public function update(Request $request, MarketingSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'repository_url' => ['required', 'url:https', 'max:255', 'regex:~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$~'],
        ]);

        $settings->update((bool) $validated['enabled'], $validated['repository_url']);

        return back()->with('status', __('Marketing page settings saved.'));
    }
}
