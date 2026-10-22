<?php

namespace App\Events\Security;

use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\SecurityExportPayload;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a suspended account is allowed to sign in again, from an incident page or `ipile:restore-user`.
 * Undo your own consequences here (unfreeze a wallet, release held payouts).
 *
 * @api
 */
class SecurityAccountRestored implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const VIA_INCIDENT = 'incident';

    public const VIA_CONSOLE = 'console';

    /**
     * @param  User|null  $restoredBy  The administrator; null from the console.
     * @param  'incident'|'console'  $via
     */
    public function __construct(
        public User $user,
        public ?User $restoredBy,
        public ?SecurityIncident $incident,
        public string $via,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        return app(SecurityExportPayload::class)->envelope('security.account_restored', now(), $this->user->getKey(), [
            'via' => $this->via,
            'incident_id' => $this->incident?->getKey(),
        ]);
    }
}
