<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThemeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Theme: site-wide colours and the landing page layout.
 */
class ThemeSettingsController extends Controller
{
    public function edit(ThemeSettings $themes): Response
    {
        return Inertia::render('Admin/Theme', [
            'currentTheme' => $themes->current(),
            'themes' => $themes->options(),
            'updateUrl' => route('admin.theme.update'),
        ]);
    }

    public function update(Request $request, ThemeSettings $themes): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(array_keys(ThemeSettings::THEMES))],
        ]);

        $themes->update($validated['theme']);

        return back()->with('status', __('Theme saved.'));
    }
}
