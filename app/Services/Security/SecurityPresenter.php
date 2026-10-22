<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Services\Auth\DeviceName;

/**
 * Shapes security events and incidents for the Aegis pages and the account security page.
 */
class SecurityPresenter
{
    public function __construct(private SecurityEventCatalog $catalog) {}

    /**
     * @return array<string, mixed>
     */
    public function event(SecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'label' => $this->catalog->label($event->type),
            'category' => $event->category,
            'user' => $event->relationLoaded('user') && $event->user !== null
                ? ['id' => $event->user->id, 'name' => $event->user->display_name, 'email' => $event->user->email]
                : null,
            'ip_address' => $event->ip_address,
            'device' => $event->user_agent === null ? null : DeviceName::fromUserAgent($event->user_agent),
            'context' => $event->context ?? [],
            'risk_score' => $event->risk_score,
            'posture_score' => $event->posture_score,
            'signals' => $event->signals ?? [],
            'recommended_action' => $this->action($event->recommended_action),
            'action_taken' => $this->action($event->action_taken),
            'incident_id' => $event->security_incident_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function incident(SecurityIncident $incident): array
    {
        $analysis = $incident->ai_analysis;

        return [
            'id' => $incident->id,
            'user' => $incident->user === null ? null : [
                'id' => $incident->user->id,
                'name' => $incident->user->display_name,
                'email' => $incident->user->email,
                'status' => $incident->user->status,
            ],
            'ip_address' => $incident->ip_address,
            'status' => $incident->status,
            'resolution' => $incident->resolution,
            'resolution_note' => $incident->resolution_note,
            'risk_score' => $incident->risk_score,
            'risk_level' => $incident->risk_level,
            'recommended_action' => $this->action($incident->recommended_action),
            'action_taken' => $this->action($incident->action_taken),
            'event_count' => $incident->event_count,
            'first_event_at' => $incident->first_event_at->toIso8601String(),
            'last_event_at' => $incident->last_event_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'resolved_by' => $incident->resolver?->display_name,
            'ai' => [
                'status' => $incident->ai_status,
                'analyzed_at' => $incident->ai_analyzed_at?->toIso8601String(),
                'analysis' => is_array($analysis) && ! isset($analysis['error']) ? $analysis : null,
                'error' => is_array($analysis) ? ($analysis['error'] ?? null) : null,
            ],
        ];
    }

    /**
     * @return array{value: string, label: string}|null
     */
    public function action(?string $value): ?array
    {
        $action = SecurityAction::tryFrom((string) $value);

        return $action === null || $action === SecurityAction::None ? null : ['value' => $action->value, 'label' => $action->label()];
    }
}
