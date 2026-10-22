<?php

namespace App\Events\Security;

use App\Models\SecurityEvent;
use App\Services\Security\SecurityExportPayload;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched for every security event Aegis saves, before incidents and responses. Use
 * {@see SecurityRiskAssessed} instead when you need the outcome.
 *
 * @api
 */
class SecurityEventRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SecurityEvent $event) {}

    /**
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        $payload = app(SecurityExportPayload::class);

        return $payload->envelope('security.event_recorded', $this->event->occurred_at, $this->event->user_id, [
            'event' => $payload->event($this->event),
        ]);
    }
}
