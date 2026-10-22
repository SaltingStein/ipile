<?php

namespace App\Services\Security;

use InvalidArgumentException;

/**
 * A kind of security event Aegis can record, such as `auth.login` or `orders.refunded`.
 *
 * @see SecurityEventCatalog for the built-in events, and {@see Security::define()} to add your own.
 */
final readonly class SecurityEventDefinition
{
    /**
     * @param  string  $key  Dotted, lower-case identifier (`area.what_happened`). Stored with each event and used as a Markov state.
     * @param  string  $category  Scope group (`authentication`, `payments`…). Administrators can switch whole categories off.
     * @param  float  $sensitivity  0-1: how much damage this action could do in the wrong hands. Scales the risk score.
     * @param  bool  $isFailure  A failed attempt (wrong password, bad 2FA code). Counted by the recent-failures signal.
     * @param  bool  $actorIsVerified  The person acting has proven they control the account. When false (e.g. a failed
     *                                 sign-in, which anyone can cause), responses stop at alerting the owner, so an attacker
     *                                 cannot sign a victim out or suspend them just by failing on purpose.
     * @param  int|null  $forcedScore  0-100 for events that are high risk by nature (e.g. asking the site assistant for
     *                                 passwords). Such events are recorded even while Aegis is off or their category is
     *                                 switched off, score at least this much, always open an incident, and apply to
     *                                 everyone, super admins included. The code that records them carries out the
     *                                 response itself, so Aegis does not add one of its own.
     * @param  SecurityAction|null  $minimumAction  The weakest response this event may get, whatever its score (e.g.
     *                                              StepUp: "always ask for a fresh two-factor code"). In enforce mode it
     *                                              sets the minimum response; in monitor mode it only shapes
     *                                              {@see Security::check()} and {@see Security::preview()} decisions.
     *                                              Failed attempts by unverified actors still stop at alerting the owner.
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $label,
        public string $description,
        public float $sensitivity = 0.5,
        public bool $isFailure = false,
        public bool $actorIsVerified = true,
        public ?int $forcedScore = null,
        public ?SecurityAction $minimumAction = null,
    ) {
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw new InvalidArgumentException("Security event keys look like [area.what_happened]; [{$key}] does not.");
        }

        if ($sensitivity < 0 || $sensitivity > 1) {
            throw new InvalidArgumentException("Sensitivity for [{$key}] must be between 0 and 1.");
        }

        if ($forcedScore !== null && ($forcedScore < 0 || $forcedScore > 100)) {
            throw new InvalidArgumentException("The forced score for [{$key}] must be between 0 and 100.");
        }

        if ($minimumAction === SecurityAction::None) {
            throw new InvalidArgumentException("Leave the minimum action for [{$key}] empty instead of setting it to None.");
        }
    }

    /**
     * @return array{key: string, category: string, label: string, description: string, sensitivity: float, is_failure: bool, actor_is_verified: bool, forced_score: int|null, minimum_action: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'category' => $this->category,
            'label' => $this->label,
            'description' => $this->description,
            'sensitivity' => $this->sensitivity,
            'is_failure' => $this->isFailure,
            'actor_is_verified' => $this->actorIsVerified,
            'forced_score' => $this->forcedScore,
            'minimum_action' => $this->minimumAction?->value,
        ];
    }
}
