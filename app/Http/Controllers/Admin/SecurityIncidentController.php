<?php

namespace App\Http\Controllers\Admin;

use App\Events\Security\SecurityAccountRestored;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeSecurityIncident;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Services\Security\Analysis\SecurityAnalyst;
use App\Services\Security\IncidentManager;
use App\Services\Security\SecurityPresenter;
use App\Services\Security\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Security → incident: the event timeline with each score explained, the AI analysis, and triage.
 */
class SecurityIncidentController extends Controller
{
    /**
     * Admin → Security → Incidents: every incident, filtered by status, with links to triage each one.
     */
    public function index(Request $request, IncidentManager $incidents, SecurityPresenter $presenter): Response
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in(IncidentManager::FILTERS)]])['status'] ?? 'active';

        return Inertia::render('Admin/SecurityIncidents', [
            'filter' => $filter,
            'filters' => IncidentManager::FILTERS,
            'counts' => [
                'open' => SecurityIncident::query()->where('status', SecurityIncident::STATUS_OPEN)->count(),
                'acknowledged' => SecurityIncident::query()->where('status', SecurityIncident::STATUS_ACKNOWLEDGED)->count(),
                'high_risk' => SecurityIncident::query()->where('status', '!=', SecurityIncident::STATUS_RESOLVED)->whereIn('risk_level', ['high', 'critical'])->count(),
                'resolved_30_days' => SecurityIncident::query()->where('status', SecurityIncident::STATUS_RESOLVED)->where('resolved_at', '>=', now()->subDays(30))->count(),
            ],
            'incidents' => $incidents->queue($filter)->through(fn (SecurityIncident $incident) => $presenter->incident($incident)),
            'canTriage' => $request->user()->can('view_security'),
            'urls' => ['index' => route('admin.security.incidents.index'), 'monitoring' => route('admin.security.index')],
        ]);
    }

    public function show(SecurityIncident $incident, SecurityPresenter $presenter, SecurityAnalyst $analyst, SecuritySettings $settings): Response
    {
        $incident->load(['user', 'resolver']);

        return Inertia::render('Admin/SecurityIncident', [
            'incident' => $presenter->incident($incident),
            'events' => $incident->events()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(fn (SecurityEvent $event) => $presenter->event($event))
                ->all(),
            'resolutions' => [
                ['value' => 'benign', 'label' => 'Benign', 'description' => 'It was the account owner; nothing to do.'],
                ['value' => 'false_positive', 'label' => 'False positive', 'description' => 'Aegis scored normal behaviour too high. Consider raising thresholds.'],
                ['value' => 'confirmed', 'label' => 'Confirmed attack', 'description' => 'Malicious. These events are left out of baseline training.'],
            ],
            'ai' => [
                'available' => $analyst->isAvailable(),
                'enabled' => $settings->aiEnabled(),
            ],
            'urls' => [
                'index' => route('admin.security.incidents.index'),
                'update' => route('admin.security.incidents.update', $incident),
                'analyze' => route('admin.security.incidents.analyze', $incident),
                'integration' => route('admin.integrations.show', 'anthropic'),
                'restore' => route('admin.security.incidents.restore', $incident),
            ],
            // Suspended accounts (e.g. by the site assistant's guard rails) can be restored from the incident.
            'canRestore' => $incident->user?->isSuspended() === true && request()->user()->can('suspend_users'),
        ]);
    }

    /**
     * Acknowledge or resolve the incident.
     */
    public function update(Request $request, SecurityIncident $incident, IncidentManager $incidents): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([SecurityIncident::STATUS_ACKNOWLEDGED, SecurityIncident::STATUS_RESOLVED])],
            'resolution' => ['required_if:status,'.SecurityIncident::STATUS_RESOLVED, 'nullable', Rule::in(SecurityIncident::RESOLUTIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['status'] === SecurityIncident::STATUS_ACKNOWLEDGED) {
            $incidents->acknowledge($incident);

            return back()->with('status', __('Incident acknowledged.'));
        }

        $incidents->resolve($incident, $request->user(), $validated['resolution'], $validated['note'] ?? null);

        return back()->with('status', __('Incident resolved. The account\'s risk posture has been reset.'));
    }

    /**
     * Lift the suspension of the incident's account, e.g. after reviewing a site assistant violation. The person
     * signs in again as normal; the incident stays open until it is resolved.
     */
    public function restoreUser(Request $request, SecurityIncident $incident, IncidentManager $incidents): RedirectResponse
    {
        $user = $incident->user;

        if ($user === null || ! $incidents->restoreAccount($user, $request->user(), $incident, SecurityAccountRestored::VIA_INCIDENT)) {
            return back()->withErrors(['restore' => __('This account is not suspended.')]);
        }

        return back()->with('status', __(':name can sign in again.', ['name' => $user->display_name]));
    }

    /**
     * Ask the AI analyst to (re)analyse the incident.
     */
    public function analyze(SecurityIncident $incident, SecurityAnalyst $analyst): RedirectResponse
    {
        if (! $analyst->isAvailable()) {
            return back()->withErrors(['analysis' => __('Enable the Anthropic integration (Admin → Integrations) first.')]);
        }

        $incident->forceFill(['ai_status' => SecurityIncident::AI_PENDING])->save();
        AnalyzeSecurityIncident::dispatch($incident->getKey());

        return back()->with('status', __('Analysis requested. It appears here when it is ready.'));
    }
}
