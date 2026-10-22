<?php

namespace App\Services\Security\Analysis;

use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Services\Auth\DeviceName;
use App\Services\Security\SecurityEventCatalog;

/**
 * Builds the redacted description of an incident that is sent to an AI analyst.
 *
 * What leaves the server: event types and labels from the catalog, scores, signal names and strengths, times
 * relative to the first event, a device name from a fixed vocabulary ("Chrome on Windows"), whether each
 * network/device was already known, role names and account facts (age, two-factor on, etc.), and numeric or
 * true/false context values.
 *
 * What never leaves: names, email addresses, phone numbers, IP addresses, raw user agents, string context
 * values and signal detail text. Nothing an attacker can type reaches the model, which also rules out
 * prompt injection through the incident.
 */
class IncidentBriefing
{
    private const MAX_EVENTS = 50;

    public function __construct(private SecurityEventCatalog $catalog) {}

    /**
     * @return array<string, mixed>
     */
    public function for(SecurityIncident $incident): array
    {
        $user = $incident->user;
        $events = $incident->events()->orderBy('occurred_at')->orderBy('id')->limit(self::MAX_EVENTS)->get();
        $firstAt = $events->first()?->occurred_at ?? $incident->first_event_at;

        return [
            'incident' => [
                'risk_score' => $incident->risk_score,
                'risk_level' => $incident->risk_level,
                'policy_recommended_action' => $incident->recommended_action,
                'action_already_taken' => $incident->action_taken,
                'event_count' => $incident->event_count,
                'duration_minutes' => (int) round($incident->first_event_at->diffInMinutes($incident->last_event_at, true)),
                'about_signed_in_account' => $user !== null,
            ],
            'account' => $user === null ? null : [
                'roles' => $user->getRoleNames()->values()->all(),
                'account_age_days' => (int) $user->created_at?->diffInDays(now(), true),
                'is_guest_account' => (bool) $user->is_guest,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'has_password' => $user->hasPassword(),
                'is_suspended' => $user->isSuspended(),
                'earlier_incidents' => SecurityIncident::query()->where('user_id', $user->getKey())->whereKeyNot($incident->getKey())->count(),
                'earlier_confirmed_incidents' => SecurityIncident::query()->where('user_id', $user->getKey())->where('resolution', 'confirmed')->count(),
            ],
            'events' => $events->map(fn (SecurityEvent $event) => [
                'seconds_after_first_event' => (int) $firstAt->diffInSeconds($event->occurred_at, true),
                'type' => $event->type,
                'label' => $this->catalog->label($event->type),
                'category' => $event->category,
                'sensitivity' => $this->catalog->has($event->type) ? $this->catalog->find($event->type)->sensitivity : null,
                'event_risk_score' => $event->risk_score,
                'account_posture_score' => $event->posture_score,
                'device' => $event->user_agent === null ? null : DeviceName::fromUserAgent($event->user_agent),
                'signals' => array_map(fn (array $signal) => [
                    'key' => $signal['key'] ?? null,
                    'score' => $signal['score'] ?? null,
                ], $event->signals ?? []),
                'context' => array_filter($event->context ?? [], fn (mixed $value) => is_int($value) || is_float($value) || is_bool($value)),
                'policy_recommended_action' => $event->recommended_action,
                'action_taken' => $event->action_taken,
            ])->all(),
        ];
    }
}
