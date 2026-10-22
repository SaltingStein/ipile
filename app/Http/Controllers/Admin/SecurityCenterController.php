<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityBaseline;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityRiskState;
use App\Services\Security\IncidentManager;
use App\Services\Security\SecurityPresenter;
use App\Services\Security\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Security: the Aegis overview with headline numbers, the incident queue and recent risky events.
 */
class SecurityCenterController extends Controller
{
    public const FILTERS = IncidentManager::FILTERS;

    public function index(Request $request, SecuritySettings $settings, SecurityPresenter $presenter, IncidentManager $manager): Response
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(self::FILTERS)]]);
        $filter = $validated['status'] ?? 'active';
        $since = now()->subDay();
        $incidentThreshold = $settings->incidentThreshold();
        $mode = $settings->mode();

        $incidents = $manager->queue($filter)->through(fn (SecurityIncident $incident) => $presenter->incident($incident));

        return Inertia::render('Admin/SecurityCenter', [
            'mode' => ['value' => $mode->value, 'label' => $mode->label(), 'description' => $mode->description()],
            'filter' => $filter,
            'filters' => self::FILTERS,
            'stats' => [
                'events_24h' => SecurityEvent::query()->where('occurred_at', '>=', $since)->count(),
                'risky_events_24h' => SecurityEvent::query()->where('occurred_at', '>=', $since)
                    ->where(fn ($query) => $query->where('risk_score', '>=', $incidentThreshold)->orWhere('posture_score', '>=', $incidentThreshold))
                    ->count(),
                'active_incidents' => SecurityIncident::query()->where('status', '!=', SecurityIncident::STATUS_RESOLVED)->count(),
                'accounts_at_risk' => SecurityRiskState::query()->where('score', '>=', 30)->count(),
                'baseline_scopes' => SecurityBaseline::query()->count(),
                'last_trained_at' => $settings->lastTrainedAt(),
            ],
            'incidents' => $incidents,
            'recentEvents' => SecurityEvent::query()
                ->with('user')
                ->where(fn ($query) => $query->where('risk_score', '>=', 30)->orWhere('posture_score', '>=', 30))
                ->latest('occurred_at')
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (SecurityEvent $event) => $presenter->event($event))
                ->all(),
            'canManage' => $request->user()->can('manage_security'),
            'canTriage' => $request->user()->can('view_security'),
            'urls' => [
                'index' => route('admin.security.index'),
                'settings' => route('admin.security.settings.edit'),
                'guide' => route('admin.security.guide'),
            ],
        ]);
    }
}
