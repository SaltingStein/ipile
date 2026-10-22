<?php

namespace App\Services\Security;

use App\Events\Security\SecurityAccountRestored;
use App\Events\Security\SecurityIncidentOpened;
use App\Events\Security\SecurityIncidentResolved;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityRiskState;
use App\Models\User;
use App\Services\Security\Markov\RiskPostureChain;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Opens incidents for risky events, groups related events into them, and closes them.
 */
class IncidentManager
{
    /** Risky events this close to an active incident's last event join it instead of opening a new one. */
    public const GROUPING_MINUTES = 60;

    /** Filters for the incident queue: open and acknowledged, resolved, or everything. */
    public const FILTERS = ['active', 'resolved', 'all'];

    public function __construct(private RiskPostureChain $posture) {}

    /**
     * The incident queue: unresolved incidents first, newest activity first.
     *
     * @return LengthAwarePaginator<int, SecurityIncident>
     */
    public function queue(string $filter, int $perPage = 20): LengthAwarePaginator
    {
        return SecurityIncident::query()
            ->with(['user', 'resolver'])
            ->when($filter === 'active', fn ($query) => $query->where('status', '!=', SecurityIncident::STATUS_RESOLVED))
            ->when($filter === 'resolved', fn ($query) => $query->where('status', SecurityIncident::STATUS_RESOLVED))
            ->orderByRaw('case when status = ? then 1 else 0 end', [SecurityIncident::STATUS_RESOLVED])
            ->latest('last_event_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Attach the event to the account's (or IP address's) active incident, or open a new one.
     */
    public function capture(SecurityEvent $event, int $score, SecurityAction $recommended): SecurityIncident
    {
        $incident = SecurityIncident::query()
            ->where('status', '!=', SecurityIncident::STATUS_RESOLVED)
            ->where('last_event_at', '>=', $event->occurred_at->copy()->subMinutes(self::GROUPING_MINUTES))
            ->when(
                $event->user_id !== null,
                fn ($query) => $query->where('user_id', $event->user_id),
                fn ($query) => $query->whereNull('user_id')->where('ip_address', $event->ip_address),
            )
            ->latest('last_event_at')
            ->first();

        $isNew = $incident === null;
        $incident ??= new SecurityIncident([
            'user_id' => $event->user_id,
            'ip_address' => $event->ip_address,
            'status' => SecurityIncident::STATUS_OPEN,
            'risk_score' => 0,
            'event_count' => 0,
            'first_event_at' => $event->occurred_at,
        ]);

        $currentRecommendation = SecurityAction::tryFrom((string) $incident->recommended_action);
        $riskScore = max($incident->risk_score, $score);

        $incident->fill([
            'risk_score' => $riskScore,
            'risk_level' => RiskPostureChain::levelFor($riskScore),
            'recommended_action' => $recommended->isStrongerThan($currentRecommendation) ? $recommended->value : $currentRecommendation?->value,
            'event_count' => $incident->event_count + 1,
            'last_event_at' => $event->occurred_at,
        ])->save();

        $event->forceFill(['security_incident_id' => $incident->getKey()])->save();

        if ($isNew) {
            SecurityIncidentOpened::dispatch($incident);
        }

        return $incident;
    }

    /**
     * Record the strongest response actually taken for the incident.
     */
    public function recordAction(SecurityIncident $incident, SecurityAction $taken): void
    {
        if ($taken->isStrongerThan(SecurityAction::tryFrom((string) $incident->action_taken))) {
            $incident->forceFill(['action_taken' => $taken->value])->save();
        }
    }

    public function acknowledge(SecurityIncident $incident): void
    {
        if ($incident->status === SecurityIncident::STATUS_OPEN) {
            $incident->forceFill(['status' => SecurityIncident::STATUS_ACKNOWLEDGED])->save();
        }
    }

    /**
     * Close the incident. The account's posture goes back to normal so future events are judged afresh.
     *
     * @param  'benign'|'false_positive'|'confirmed'  $resolution
     */
    public function resolve(SecurityIncident $incident, User $resolver, string $resolution, ?string $note = null): void
    {
        $incident->forceFill([
            'status' => SecurityIncident::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolution_note' => $note,
            'resolved_by' => $resolver->getKey(),
            'resolved_at' => now(),
        ])->save();

        if ($incident->user_id !== null) {
            SecurityRiskState::query()->where('user_id', $incident->user_id)->update([
                'belief' => json_encode($this->posture->initial()),
                'score' => 0,
                'level' => 'normal',
                'last_action' => null,
            ]);
        }

        SecurityIncidentResolved::dispatch($incident);
    }

    /**
     * Lift a suspension so the person can sign in again. The incident (when given) stays open until it is resolved.
     * Returns false when the account was not suspended.
     *
     * @param  'incident'|'console'  $via
     */
    public function restoreAccount(User $user, ?User $restoredBy, ?SecurityIncident $incident, string $via): bool
    {
        if (! $user->isSuspended()) {
            return false;
        }

        $user->forceFill(['status' => 'active'])->save();

        SecurityAccountRestored::dispatch($user, $restoredBy, $incident, $via);

        return true;
    }
}
