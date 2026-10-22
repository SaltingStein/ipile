<?php

namespace App\Services\Assistant;

/**
 * The outcome of screening one message to the site assistant.
 *
 * - `allowed`: the assistant may answer.
 * - `violation`: the message asks for protected data; {@see AssistantGuard::enforce()} must run.
 * - `unchecked`: the AI check could not run, so the message is not answered (but nobody is punished). `reason`
 *   says why: {@see NO_MODEL}, {@see NO_CREDIT}, {@see UNREACHABLE} or {@see FAILED}.
 */
final readonly class AssistantVerdict
{
    public const CREDENTIALS = 'credentials';

    public const OTHER_PEOPLE = 'other_people';

    public const SYSTEM_INTERNALS = 'system_internals';

    /** The chosen AI model is not set, or is switched off in Admin → Integrations. */
    public const NO_MODEL = 'no_model';

    /** The AI provider refused the request because the account has no credit or quota left. */
    public const NO_CREDIT = 'no_credit';

    /** The AI provider could not be reached (timeouts, dropped connections), even after retrying. */
    public const UNREACHABLE = 'unreachable';

    /** Any other error from the AI provider. */
    public const FAILED = 'failed';

    private function __construct(
        public string $outcome,
        public ?string $category = null,
        public ?string $reason = null,
        public ?string $source = null,
    ) {}

    public static function allowed(): self
    {
        return new self('allowed');
    }

    public static function violation(string $category, string $reason, string $source): self
    {
        return new self('violation', $category, $reason, $source);
    }

    public static function unchecked(string $reason = self::FAILED): self
    {
        return new self('unchecked', reason: $reason);
    }

    public function isAllowed(): bool
    {
        return $this->outcome === 'allowed';
    }

    public function isViolation(): bool
    {
        return $this->outcome === 'violation';
    }

    /**
     * Plain-language description for the security log and the administrators' email.
     */
    public function activity(): string
    {
        return match ($this->category) {
            self::CREDENTIALS => 'Asked the site assistant for passwords, one-time codes, recovery codes or secret keys',
            self::OTHER_PEOPLE => 'Asked the site assistant for other people’s data',
            self::SYSTEM_INTERNALS => 'Asked the site assistant for protected system data or to get round its rules',
            default => 'Asked the site assistant for protected data',
        };
    }
}
