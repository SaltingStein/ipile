<?php

namespace App\Events\Security;

use App\Models\SecurityIncident;
use App\Services\Security\SecurityExportPayload;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a risky event opens a new incident (not when later events join it). A good place to page
 * whoever is on call.
 *
 * @api
 */
class SecurityIncidentOpened
{
    use Dispatchable;

    public function __construct(public SecurityIncident $incident) {}

    /**
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        $payload = app(SecurityExportPayload::class);

        return $payload->envelope('security.incident_opened', $this->incident->first_event_at ?? now(), $this->incident->user_id, [
            'incident' => $payload->incident($this->incident),
        ]);
    }
}
