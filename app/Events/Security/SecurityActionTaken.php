<?php

namespace App\Events\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityExportPayload;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after Aegis (or the site assistant's guard rails) carried out a response on an account: an alert, a
 * password re-check, signing out everywhere or a suspension. To attach your own consequence to one kind of response,
 * {@see Security::onAction()} is simpler.
 *
 * @api
 */
class SecurityActionTaken implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const SOURCE_AEGIS = 'aegis';

    public const SOURCE_ASSISTANT = 'assistant';

    /**
     * @param  SecurityAction  $action  What was actually done (a password re-check that cannot apply becomes an alert).
     * @param  SecurityAction  $requested  What was asked for.
     * @param  SecurityEvent|null  $event  The event that caused it; null only when recording that event failed.
     * @param  'aegis'|'assistant'  $source
     */
    public function __construct(
        public User $user,
        public SecurityAction $action,
        public SecurityAction $requested,
        public ?SecurityEvent $event,
        public ?SecurityIncident $incident,
        public string $source = self::SOURCE_AEGIS,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        return app(SecurityExportPayload::class)->envelope('security.action_taken', now(), $this->user->getKey(), [
            'action' => $this->action->value,
            'requested_action' => $this->requested->value,
            'source' => $this->source,
            'event_id' => $this->event?->getKey(),
            'event_type' => $this->event?->type,
            'incident_id' => $this->incident?->getKey(),
        ]);
    }
}
