<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records audited actions.
 *
 *     Audit::record('user.roles_changed', $user, before: ['roles' => $old], after: ['roles' => $new]);
 *
 * Never pass secrets: values under names that look secret are dropped, and the
 * Auditable trait redacts each model's personal fields before they get here.
 */
class Audit
{
    /** Values longer than this are cut, so one huge setting cannot bloat the log. */
    private const MAX_VALUE_LENGTH = 1000;

    /** Attribute names that are never written to the log, whatever the caller passes. */
    private const SECRET_PATTERN = '/(password|secret|token|recovery_code|api_key|private_key|credential)/i';

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(string $action, ?Model $subject = null, array $before = [], array $after = []): AuditLog
    {
        $changes = array_filter([
            'before' => self::clean($before),
            'after' => self::clean($after),
        ], fn (array $values) => $values !== []);

        return AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            // Null outside a web request (queue workers, scheduled commands).
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent() === null ? null : Str::limit(request()->userAgent(), 250, ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (preg_match(self::SECRET_PATTERN, (string) $key)) {
                continue;
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…';
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
