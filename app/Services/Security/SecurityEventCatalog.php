<?php

namespace App\Services\Security;

use InvalidArgumentException;

/**
 * Every event type Aegis knows about: the built-in ones below plus any registered with {@see Security::define()}.
 * Registered as a singleton, so definitions added in a service provider's `boot()` last for the whole request.
 */
class SecurityEventCatalog
{
    /**
     * Category => [label, description]. Unknown categories from custom events are shown by key.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $categories = [
        'authentication' => ['Authentication', 'Sign-in, sign-out, failed attempts, lockouts and two-factor challenges.'],
        'account' => ['Account', 'Sign-up, email verification, password and two-factor changes, signing out other sessions.'],
        'api' => ['API access', 'Creating and revoking API tokens.'],
        'teams' => ['Teams', 'Team creation and deletion, invitations, role changes, removals and ownership transfers.'],
        'payments' => ['Payments', 'Starting a payment.'],
        'administration' => ['Administration', 'Changes to site settings, access control, integrations and Aegis itself.'],
        'assistant' => ['Site assistant', 'Which mode answered each message (help, site guidance or analytics), and requests for passwords, codes, keys or other people’s data. Those requests are always recorded, even while Aegis is off.'],
        'mcp' => ['Assistant widget data', 'Reads by the third-party assistant widget through the data connection (MCP), and refused attempts.'],
    ];

    /** @var array<string, SecurityEventDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach ($this->builtIn() as $definition) {
            $this->definitions[$definition->key] = $definition;
        }
    }

    /**
     * Add (or replace) an event type.
     */
    public function register(SecurityEventDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    /**
     * Give a custom category a readable label and description in the security centre.
     */
    public function registerCategory(string $key, string $label, string $description = ''): void
    {
        $this->categories[$key] = [$label, $description];
    }

    /**
     * @return array<string, SecurityEventDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @throws InvalidArgumentException for an unknown key, so a typo fails loudly in development.
     */
    public function find(string $key): SecurityEventDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException(
            "Unknown security event [{$key}]. Register it with Security::define() in a service provider."
        );
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions);
    }

    /**
     * Event keys flagged as failed attempts.
     *
     * @return list<string>
     */
    public function failureKeys(): array
    {
        return array_values(array_map(
            fn (SecurityEventDefinition $definition) => $definition->key,
            array_filter($this->definitions, fn (SecurityEventDefinition $definition) => $definition->isFailure),
        ));
    }

    /**
     * Every category in use, with its events.
     *
     * @return list<array{key: string, label: string, description: string, events: list<array<string, mixed>>}>
     */
    public function categories(): array
    {
        $grouped = [];

        foreach ($this->definitions as $definition) {
            $grouped[$definition->category][] = $definition->toArray();
        }

        $categories = [];
        foreach ($grouped as $key => $events) {
            [$label, $description] = $this->categories[$key] ?? [ucfirst(str_replace('_', ' ', $key)), ''];
            $categories[] = ['key' => $key, 'label' => $label, 'description' => $description, 'events' => $events];
        }

        return $categories;
    }

    public function label(string $key): string
    {
        return $this->definitions[$key]->label ?? $key;
    }

    /**
     * @return list<SecurityEventDefinition>
     */
    private function builtIn(): array
    {
        return [
            new SecurityEventDefinition('auth.login', 'authentication', 'Signed in',
                'A browser or app signed in to the account.', 0.3),
            new SecurityEventDefinition('auth.login_failed', 'authentication', 'Sign-in failed',
                'Wrong email or password. Recorded against the account when the email matches one.', 0.4, isFailure: true, actorIsVerified: false),
            new SecurityEventDefinition('auth.lockout', 'authentication', 'Sign-in locked out',
                'Too many failed sign-ins from one IP address for one email.', 0.7, isFailure: true, actorIsVerified: false),
            new SecurityEventDefinition('auth.two_factor_failed', 'authentication', 'Two-factor code rejected',
                'The password was right but the authenticator or recovery code was not.', 0.5, isFailure: true, actorIsVerified: false),
            new SecurityEventDefinition('auth.logout', 'authentication', 'Signed out',
                'A browser signed out.', 0.05),
            new SecurityEventDefinition('auth.password_reset', 'authentication', 'Password reset by email',
                'The password was replaced using a reset link.', 0.7),

            new SecurityEventDefinition('account.registered', 'account', 'Account created',
                'Someone signed up (email or social sign-in).', 0.2),
            new SecurityEventDefinition('account.email_verified', 'account', 'Email verified',
                'The email address was confirmed.', 0.1),
            new SecurityEventDefinition('account.password_changed', 'account', 'Password changed',
                'The password was changed from the security settings page.', 0.7),
            new SecurityEventDefinition('account.two_factor_enabled', 'account', 'Two-factor turned on',
                'An authenticator app was set up.', 0.4),
            new SecurityEventDefinition('account.two_factor_disabled', 'account', 'Two-factor turned off',
                'The authenticator app was removed.', 0.9),
            new SecurityEventDefinition('account.two_factor_confirmed', 'account', 'Two-factor confirmed again',
                'The person entered a fresh authenticator or recovery code before a protected action.', 0.2),
            new SecurityEventDefinition('account.sessions_revoked', 'account', 'Other sessions signed out',
                'The owner signed out every other browser.', 0.4),

            new SecurityEventDefinition('api.token_created', 'api', 'API token created',
                'An app or device received a new API token.', 0.5),
            new SecurityEventDefinition('api.token_revoked', 'api', 'API token revoked',
                'An API token was deleted.', 0.2),

            new SecurityEventDefinition('team.created', 'teams', 'Team created',
                'A new team was created.', 0.1),
            new SecurityEventDefinition('team.deleted', 'teams', 'Team deleted',
                'A team and its memberships were deleted.', 0.8),
            new SecurityEventDefinition('team.member_invited', 'teams', 'Team invitation sent',
                'Someone was invited to a team.', 0.3),
            new SecurityEventDefinition('team.member_role_changed', 'teams', 'Team role changed',
                'A member\'s role in a team changed.', 0.6),
            new SecurityEventDefinition('team.member_removed', 'teams', 'Team member removed',
                'A member was removed from a team.', 0.5),
            new SecurityEventDefinition('team.ownership_transferred', 'teams', 'Team ownership transferred',
                'A team was handed to a new owner.', 0.9),

            new SecurityEventDefinition('payment.initiated', 'payments', 'Payment started',
                'A payment was created with a payment gateway.', 0.6),

            new SecurityEventDefinition('admin.settings_changed', 'administration', 'Admin settings changed',
                'An administrator saved an admin settings page.', 0.7),
            new SecurityEventDefinition('admin.access_changed', 'administration', 'Roles or permissions changed',
                'Role permissions were changed.', 0.9),
            new SecurityEventDefinition('admin.integration_changed', 'administration', 'Integration changed',
                'Integration credentials or the enabled state were saved.', 0.9),
            new SecurityEventDefinition('admin.security_changed', 'administration', 'Aegis settings changed',
                'The security centre mode, thresholds, scope or AI settings were saved.', 1.0),

            // High risk by nature: App\Services\Assistant\AssistantGuard signs the person out and alerts administrators.
            new SecurityEventDefinition('assistant.sensitive_request', 'assistant', 'Asked the assistant for protected data',
                'Someone asked the site assistant for passwords, one-time codes, recovery codes, API or payment keys, or other people’s data. They are signed out everywhere and must pass two-factor again; accounts without two-factor are suspended.', 1.0, forcedScore: 80),

            new SecurityEventDefinition('assistant.message_routed', 'assistant', 'Assistant answered a message',
                'The built-in assistant answered a screened message. Records the intent, the mode that answered and whether it fell back from a staff mode, never the message.', 0.1),

            // The widget service calls from its own servers, not the person's browser, so responses stop at an alert.
            new SecurityEventDefinition('mcp.tool_called', 'mcp', 'Widget read data',
                'The third-party assistant widget read data for this person through the data connection. Records the tool and the outcome, never the data.', 0.1, actorIsVerified: false),
            new SecurityEventDefinition('mcp.access_refused', 'mcp', 'Widget data request refused',
                'A request to the data connection came from a network that is not allowed, or carried a missing, expired or forged claim.', 0.6, isFailure: true, actorIsVerified: false),
        ];
    }
}
