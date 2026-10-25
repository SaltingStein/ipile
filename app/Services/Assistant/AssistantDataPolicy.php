<?php

namespace App\Services\Assistant;

use Closure;

/**
 * The hard "no" on what the site assistant can read. While the assistant's tools run (see {@see run()}), every
 * database query is checked *before it is sent* (a `beforeExecuting` hook registered in AppServiceProvider) and
 * refused when it touches:
 *
 * - a blocked table ({@see BLOCKED_TABLES}): integration credentials such as payment gateway keys, sessions,
 *   tokens, trusted devices, password resets, settings, the security log, messages and jobs;
 * - a protected column ({@see PROTECTED_COLUMNS}): passwords, two-factor secrets and recovery codes, remember
 *   tokens, raw payment gateway replies and form answers;
 * - or reads every column (`select *`) of a table with protected columns, which would include them unseen.
 *
 * This does not depend on the AI model behaving: a refused query never runs, whatever the model asked for.
 * The lists are code, not settings, so they cannot be loosened from the dashboard. Builders add their own sensitive
 * tables and columns with {@see block()}; nothing can remove an entry.
 */
class AssistantDataPolicy
{
    /**
     * @var list<string>
     */
    public const BLOCKED_TABLES = [
        'integrations',
        'oauth_providers',
        'social_accounts',
        'password_reset_tokens',
        'magic_links',
        'sessions',
        'personal_access_tokens',
        'trusted_devices',
        'team_invitations',
        'settings',
        'security_events',
        'security_incidents',
        'security_risk_states',
        'security_baselines',
        'sms_messages',
        'agent_conversations',
        'assistant_conversations',
        'assistant_messages',
        'ai_interactions',
        'agent_conversation_messages',
        'translation_strings',
        'translations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ];

    /**
     * Table => columns that may never be read. A table listed here may only be read with named columns.
     *
     * @var array<string, list<string>>
     */
    public const PROTECTED_COLUMNS = [
        'users' => ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'],
        'payments' => ['gateway_response'],
        'form_submissions' => ['data'],
    ];

    private static int $depth = 0;

    /**
     * Keep the assistant away from one of your tables, or only from some of its columns (the table may then be read
     * with named columns only). Call it in a service provider's `boot()`. Append-only.
     *
     * @param  list<string>  $columns
     *
     * @api
     */
    public static function block(string $table, array $columns = []): void
    {
        app(AssistantExtensions::class)->block($table, $columns);
    }

    /**
     * Ìpìlẹ̀'s blocked tables plus the ones builders added.
     *
     * @return list<string>
     */
    public static function blockedTables(): array
    {
        return [...self::BLOCKED_TABLES, ...app(AssistantExtensions::class)->blockedTables()];
    }

    /**
     * Ìpìlẹ̀'s protected columns plus the ones builders added.
     *
     * @return array<string, list<string>>
     */
    public static function protectedColumns(): array
    {
        $columns = self::PROTECTED_COLUMNS;

        foreach (app(AssistantExtensions::class)->protectedColumns() as $table => $extra) {
            $columns[$table] = array_values(array_unique([...($columns[$table] ?? []), ...$extra]));
        }

        return $columns;
    }

    /**
     * Run the assistant's data access with the policy switched on.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws BlockedDataAccess when the callback tries to read protected data
     */
    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Called before every query. Does nothing unless the assistant's tools are running.
     *
     * @throws BlockedDataAccess
     */
    public static function inspect(string $sql): void
    {
        if (! self::isActive()) {
            return;
        }

        $reason = self::violation($sql);

        if ($reason !== null) {
            throw new BlockedDataAccess($reason);
        }
    }

    /**
     * Why a query is refused, or null when it is allowed.
     */
    public static function violation(string $sql): ?string
    {
        $normalised = strtolower((string) preg_replace('/[`"\[\]]/', '', $sql));

        foreach (self::blockedTables() as $table) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/', $normalised) === 1) {
                return "the {$table} table is not available to the assistant";
            }
        }

        // `count(*)` reads no columns, so it is fine; any other `*` would read protected columns too.
        $withoutCounts = (string) preg_replace('/count\(\s*\*\s*\)/', 'count(1)', $normalised);

        foreach (self::protectedColumns() as $table => $columns) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/', $withoutCounts) !== 1) {
                continue;
            }

            foreach ($columns as $column) {
                if (preg_match('/\b'.preg_quote($column, '/').'\b/', $withoutCounts) === 1) {
                    return "the {$table}.{$column} column is not available to the assistant";
                }
            }

            if (preg_match('/(^|[\s,(.])\*/', $withoutCounts) === 1) {
                return "reading every column of {$table} is not allowed for the assistant";
            }
        }

        return null;
    }
}
