<?php

namespace App\Services\Security;

/**
 * The response ladder, weakest first. Each rung has its own threshold in the security centre settings.
 */
enum SecurityAction: string
{
    case None = 'none';
    case Notify = 'notify';
    case StepUp = 'step_up';
    case RevokeSessions = 'revoke_sessions';
    case Suspend = 'suspend';

    /**
     * Rungs that can be given a threshold, in the order they are checked (strongest first).
     *
     * @return list<self>
     */
    public static function ladder(): array
    {
        return [self::Suspend, self::RevokeSessions, self::StepUp, self::Notify];
    }

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Notify => 1,
            self::StepUp => 2,
            self::RevokeSessions => 3,
            self::Suspend => 4,
        };
    }

    public function isStrongerThan(?self $other): bool
    {
        return $this->rank() > ($other?->rank() ?? 0);
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No action',
            self::Notify => 'Alert the account owner',
            self::StepUp => 'Ask for the password again',
            self::RevokeSessions => 'Sign out everywhere',
            self::Suspend => 'Suspend the account',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'Recorded only.',
            self::Notify => 'Sends the "Suspicious activity" notification (at most once an hour per account).',
            self::StepUp => 'The current browser must re-enter its password before it can continue. Falls back to an alert for API tokens and accounts without a password.',
            self::RevokeSessions => 'Ends every browser session, deletes every API token and trusted device, and alerts the owner.',
            self::Suspend => 'Signs out everywhere and suspends the account until an administrator restores it.',
        };
    }
}
