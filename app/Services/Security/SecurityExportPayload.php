<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The stable, versioned shape of Aegis activity for SIEMs, chat channels and webhooks. Every security lifecycle
 * event's `toExport()` uses it, so a listener can forward `$event->toExport()` as it is.
 *
 * Schema 1 holds ids, types, scores, levels, actions and timestamps only: never names, emails, IP addresses, user
 * agents, signal details or event context. To correlate activity across events without learning who it is, turn on
 * user references with {@see Security::exportUserReferences()}: a keyed hash of the account id, stable for one key.
 *
 * Registered as a singleton.
 *
 * @api
 */
class SecurityExportPayload
{
    public const SCHEMA = 1;

    private ?string $referenceKey = null;

    /**
     * Add `user_reference` (HMAC-SHA256 of the account id) to every payload. Defaults to the application key; pass
     * your own key to keep references stable across key rotations or shared with another system.
     */
    public function includeUserReferences(?string $key = null): void
    {
        $key ??= (string) config('app.key');

        if ($key === '') {
            throw new InvalidArgumentException('A key is needed to hash user references.');
        }

        $this->referenceKey = $key;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function envelope(string $type, CarbonInterface $occurredAt, ?int $userId, array $data): array
    {
        return [
            'schema' => self::SCHEMA,
            'type' => $type,
            'occurred_at' => $occurredAt->toIso8601String(),
            'user_reference' => $this->userReference($userId),
            ...$data,
        ];
    }

    /**
     * @return array{id: int, type: string, category: string, risk_score: int, posture_score: int, recommended_action: string|null, action_taken: string|null, signals: list<array{key: string, score: float}>, incident_id: int|null, occurred_at: string}
     */
    public function event(SecurityEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'type' => $event->type,
            'category' => $event->category,
            'risk_score' => (int) $event->risk_score,
            'posture_score' => (int) $event->posture_score,
            'recommended_action' => $event->recommended_action,
            'action_taken' => $event->action_taken,
            'signals' => collect($event->signals ?? [])
                ->map(fn (array $signal) => ['key' => (string) $signal['key'], 'score' => (float) $signal['score']])
                ->values()
                ->all(),
            'incident_id' => $event->security_incident_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, status: string, risk_score: int, risk_level: string|null, recommended_action: string|null, action_taken: string|null, event_count: int, resolution: string|null, first_event_at: string|null, last_event_at: string|null, resolved_at: string|null}
     */
    public function incident(SecurityIncident $incident): array
    {
        return [
            'id' => $incident->getKey(),
            'status' => $incident->status,
            'risk_score' => (int) $incident->risk_score,
            'risk_level' => $incident->risk_level,
            'recommended_action' => $incident->recommended_action,
            'action_taken' => $incident->action_taken,
            'event_count' => (int) $incident->event_count,
            'resolution' => $incident->resolution,
            'first_event_at' => $incident->first_event_at?->toIso8601String(),
            'last_event_at' => $incident->last_event_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }

    public function userReference(?int $userId): ?string
    {
        if ($userId === null || $this->referenceKey === null) {
            return null;
        }

        return hash_hmac('sha256', 'aegis-user:'.$userId, $this->referenceKey);
    }
}
