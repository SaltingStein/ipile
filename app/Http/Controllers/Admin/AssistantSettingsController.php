<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiModels;
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Platform → Site assistant: switch the assistant on, choose its AI model, and choose which business
 * figures it may read. The protected data list is shown but cannot be changed here. Routes require `manage_settings`.
 */
class AssistantSettingsController extends Controller
{
    public function edit(AssistantSettings $settings, AiModels $models): Response
    {
        return Inertia::render('Admin/Assistant', [
            'settings' => $settings->all(),
            'widgetSettingsUrl' => request()->user()->can('manage_integrations') ? route('admin.integrations.show', 'assistant_widget') : null,
            'providers' => collect($models->enabled())->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'metrics' => collect(AssistantMetrics::definitions())->map(fn (array $metric, string $key) => ['key' => $key, ...$metric])->values(),
            'blockedTables' => AssistantDataPolicy::blockedTables(),
            'protectedColumns' => AssistantDataPolicy::protectedColumns(),
            'maxDailyMessages' => AssistantSettings::MAX_DAILY_MESSAGES,
            'staffModes' => array_map(fn (AssistantIntent $mode) => ['value' => $mode->value, 'label' => $mode->label(), 'permission' => $mode->permission()], AssistantIntent::internal()),
            // The saved model was switched off (or removed) in Integrations, so the assistant cannot answer.
            'providerUnavailable' => $settings->provider() !== null && ! array_key_exists((string) $settings->provider(), $models->enabled()),
        ]);
    }

    public function update(Request $request, AssistantSettings $settings, AiModels $models): RedirectResponse
    {
        $source = $request->input('source', $settings->source());
        $builtIn = $source === 'built_in';
        $validated = $request->validate([
            'source' => ['sometimes', 'required', Rule::in(AssistantSettings::SOURCES)],
            'enabled' => ['required', 'boolean'],
            'provider' => ['nullable', Rule::requiredIf($builtIn && $request->boolean('enabled')), 'string', Rule::when($builtIn, [Rule::in(array_keys($models->enabled()))])],
            'metrics' => ['present', 'array'],
            'metrics.*' => ['string', 'distinct', Rule::in(array_keys(AssistantMetrics::definitions()))],
            'daily_messages' => ['required', 'integer', 'min:1', 'max:'.AssistantSettings::MAX_DAILY_MESSAGES],
            'lockout' => ['required', Rule::in(AssistantSettings::LOCKOUTS)],
            'staff_modes' => ['sometimes', 'array'],
            'staff_modes.*' => ['string', 'distinct', Rule::in(array_map(fn (AssistantIntent $mode) => $mode->value, AssistantIntent::internal()))],
        ], [
            'provider.required' => 'Choose the AI model that answers.',
            'provider.in' => 'Switch this AI model on in Admin → Integrations first.',
        ]);

        $settings->update((bool) $validated['enabled'], $validated['provider'] ?? null, $validated['metrics'], (int) $validated['daily_messages'], $validated['lockout'], $source, $validated['staff_modes'] ?? null);

        return back()->with('status', __('Assistant settings saved.'));
    }
}
