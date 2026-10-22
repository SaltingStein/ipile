<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;

/**
 * Aegis's answer to "should this risky action go ahead?", from {@see Security::check()} or {@see Security::preview()}.
 *
 * The decision is advisory: nothing is blocked until you act on it, usually with {@see authorize()}.
 *
 *     Security::check('payout.requested', context: ['amount_kobo' => $amount])->authorize();
 *
 * Responses map to outcomes: no action or an alert → allow; a password re-check (or an event's `minimumAction` of
 * StepUp) → a fresh two-factor code; signing out everywhere or suspension → deny.
 *
 * @api
 */
final readonly class SecurityDecision
{
    /**
     * @param  string  $type  The event type that was checked.
     * @param  int  $score  0-100: the higher of the event's own score and the account's posture score.
     * @param  SecurityAction  $action  The response Aegis recommends (it may already have been taken in enforce mode).
     * @param  string  $reason  Plain words you can show the person.
     * @param  SecurityEvent|null  $event  The recorded event; null for previews and when nothing was recorded.
     */
    public function __construct(
        public SecurityOutcome $outcome,
        public string $type,
        public int $score,
        public SecurityAction $action,
        public string $reason,
        public ?SecurityEvent $event = null,
        public bool $isPreview = false,
    ) {}

    public static function allow(string $type = '', ?string $reason = null): self
    {
        return new self(SecurityOutcome::Allow, $type, 0, SecurityAction::None, $reason ?? __('Allowed.'));
    }

    public static function challenge(string $type = '', ?string $reason = null): self
    {
        return new self(SecurityOutcome::Challenge, $type, 0, SecurityAction::StepUp, $reason ?? self::challengeReason());
    }

    public static function deny(string $type = '', ?string $reason = null): self
    {
        return new self(SecurityOutcome::Deny, $type, 0, SecurityAction::Suspend, $reason ?? self::denyReason());
    }

    public static function challengeReason(): string
    {
        return __('For your security, confirm it is you with a code from your authenticator app first.');
    }

    public static function denyReason(): string
    {
        return __('For your security, this action has been blocked. Contact support if you need help.');
    }

    public function allowed(): bool
    {
        return $this->outcome === SecurityOutcome::Allow;
    }

    public function requiresFreshTwoFactor(): bool
    {
        return $this->outcome === SecurityOutcome::Challenge;
    }

    public function denied(): bool
    {
        return $this->outcome === SecurityOutcome::Deny;
    }

    /**
     * Stop here unless the action may go ahead. The exceptions render themselves: a challenge sends browsers to the
     * two-factor confirmation page (and back afterwards) or answers JSON callers with 423; a denial goes back with
     * the reason as a `security` error, or answers JSON callers with 403.
     *
     * @throws SecurityChallengeRequired
     * @throws SecurityActionDenied
     */
    public function authorize(): void
    {
        if ($this->requiresFreshTwoFactor()) {
            throw new SecurityChallengeRequired($this->reason, decision: $this);
        }

        if ($this->denied()) {
            throw new SecurityActionDenied($this);
        }
    }

    /**
     * @return array{outcome: string, type: string, score: int, action: string, reason: string, event_id: int|null, is_preview: bool}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'type' => $this->type,
            'score' => $this->score,
            'action' => $this->action->value,
            'reason' => $this->reason,
            'event_id' => $this->event?->getKey(),
            'is_preview' => $this->isPreview,
        ];
    }
}
