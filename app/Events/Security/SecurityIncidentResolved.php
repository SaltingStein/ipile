<?php

namespace App\Events\Security;

use App\Models\SecurityIncident;
use App\Services\Security\SecurityExportPayload;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when an administrator resolves an incident (benign, false positive or confirmed attack).
 *
 * @api
 */
class SecurityIncidentResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SecurityIncident $incident) {}

    /**
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        $payload = app(SecurityExportPayload::class);

        return $payload->envelope('security.incident_resolved', $this->incident->resolved_at ?? now(), $this->incident->user_id, [
            'incident' => $payload->incident($this->incident),
        ]);
    }
}
