<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiModels;
use App\Services\Security\Analysis\SecurityAnalyst;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventCatalog;
use App\Services\Security\SecurityMode;
use App\Services\Security\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Security → Settings: mode (including off), response thresholds, scope, retention and the AI analyst.
 */
class SecuritySettingsController extends Controller
{
    public function edit(SecuritySettings $settings, SecurityEventCatalog $catalog, SecurityAnalyst $analyst, AiModels $models): Response
    {
        return Inertia::render('Admin/SecuritySettings', [
            'settings' => $settings->toArray(),
            'modes' => array_map(fn (SecurityMode $mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->description(),
            ], SecurityMode::cases()),
            'actions' => array_map(fn (SecurityAction $action) => [
                'value' => $action->value,
                'label' => $action->label(),
                'description' => $action->description(),
            ], array_reverse(SecurityAction::ladder())),
            'categories' => array_map(fn (array $category) => [
                'key' => $category['key'],
                'label' => $category['label'],
                'description' => $category['description'],
                'event_count' => count($category['events']),
            ], $catalog->categories()),
            'aiAvailable' => $analyst->isAvailable(),
            'aiProviders' => collect($models->enabled())->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'urls' => [
                'update' => route('admin.security.settings.update'),
                'index' => route('admin.security.index'),
                'guide' => route('admin.security.guide'),
                'integration' => route('admin.integrations.index'),
            ],
        ]);
    }

    public function update(Request $request, SecuritySettings $settings, SecurityEventCatalog $catalog, AiModels $models): RedirectResponse
    {
        $threshold = ['nullable', 'integer', 'min:1', 'max:100'];
        $validated = $request->validate([
            'mode' => ['required', Rule::enum(SecurityMode::class)],
            'thresholds' => ['required', 'array'],
            'thresholds.notify' => $threshold,
            'thresholds.step_up' => $threshold,
            'thresholds.revoke_sessions' => $threshold,
            'thresholds.suspend' => $threshold,
            'incident_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'disabled_categories' => ['present', 'array'],
            'disabled_categories.*' => ['string', Rule::in(array_column($catalog->categories(), 'key'))],
            'retention_days' => ['required', 'integer', 'min:7', 'max:730'],
            'ai_enabled' => ['required', 'boolean'],
            'ai_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            // Optional, so older clients keep the saved choice; must be a switched-on AI model.
            'ai_provider' => ['sometimes', 'nullable', Rule::in(array_keys($models->enabled()))],
        ]);

        $this->ensureLadderIsOrdered($validated['thresholds']);

        // Record before switching off (afterwards nothing is recorded), and after switching on.
        $turningOff = $validated['mode'] === SecurityMode::Off->value;
        if ($turningOff) {
            Security::record('admin.security_changed', ['mode' => $validated['mode']]);
        }

        $settings->update([
            ...$validated,
            'thresholds' => array_map(fn (mixed $value) => $value === null ? null : (int) $value, $validated['thresholds']),
            'ai_enabled' => (bool) $validated['ai_enabled'],
        ]);

        if (! $turningOff) {
            Security::record('admin.security_changed', ['mode' => $validated['mode']]);
        }

        return back()->with('status', __('Monitoring settings saved.'));
    }

    /**
     * A stronger response must not have a lower threshold than a weaker one.
     *
     * @param  array<string, int|string|null>  $thresholds
     *
     * @throws ValidationException
     */
    private function ensureLadderIsOrdered(array $thresholds): void
    {
        $previous = null;

        foreach (array_reverse(SecurityAction::ladder()) as $action) {
            $value = $thresholds[$action->value] ?? null;

            if ($value === null) {
                continue;
            }

            if ($previous !== null && (int) $value < $previous['value']) {
                throw ValidationException::withMessages([
                    "thresholds.{$action->value}" => __(':action must not be lower than :weaker (:value).', [
                        'action' => $action->label(),
                        'weaker' => $previous['label'],
                        'value' => $previous['value'],
                    ]),
                ]);
            }

            $previous = ['value' => (int) $value, 'label' => $action->label()];
        }
    }
}
