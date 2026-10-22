<?php

namespace App\Events\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Services\Security\SecurityExportPayload;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after every recorded security event has been scored (and any response taken).
 *
 * Listen for it to forward events to a SIEM, a chat channel or your own analytics. Listeners run inside the
 * request that caused the event, so queue anything slow (implement ShouldQueue on the listener).
 *
 * @api
 */
class SecurityRiskAssessed
{
    use Dispatchable;

    /**
     * @param  int  $decisionScore  The higher of the event's own score and the account's posture score (0-100).
     */
    public function __construct(
        public SecurityEvent $event,
        public int $decisionScore,
        public ?SecurityIncident $incident,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        $payload = app(SecurityExportPayload::class);

        return $payload->envelope('security.risk_assessed', $this->event->occurred_at, $this->event->user_id, [
            'decision_score' => $this->decisionScore,
            'event' => $payload->event($this->event),
            'incident' => $this->incident === null ? null : $payload->incident($this->incident),
        ]);
    }
}
