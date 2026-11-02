<?php

namespace App\Models\Concerns;

use App\Services\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes an audit entry when the model is created, changed, deleted or restored.
 *
 * Models can set:
 *  - $auditRedacted: personal fields recorded as changed, with the values hidden
 *    (so the log never keeps a copy of data the person later asks to delete)
 *  - $auditIgnored:  fields too noisy to audit (timestamps, last-seen markers)
 *
 * Fields in $hidden are always redacted.
 */
trait Auditable
{
    public const AUDIT_REDACTED = '[redacted]';

    /** @var list<string> */
    private static array $alwaysIgnored = ['created_at', 'updated_at', 'deleted_at', 'remember_token'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', [], $model->auditableValues(array_keys($model->getAttributes()))));

        static::updated(function (Model $model): void {
            $keys = array_keys($model->getChanges());
            $after = $model->auditableValues($keys);

            if ($after === []) {
                return;
            }

            $before = $model->auditableValues(array_keys($after), original: true);
            $model->writeAudit('updated', $before, $after);
        });

        // "deleted" for a soft delete or a plain delete; "force_deleted" only when a soft-deletable record is removed for good.
        static::deleted(fn (Model $model) => $model->writeAudit(
            method_exists($model, 'isForceDeleting') && $model->isForceDeleting() ? 'force_deleted' : 'deleted'
        ));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->writeAudit('restored'));
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function auditableValues(array $keys, bool $original = false): array
    {
        $ignored = [...self::$alwaysIgnored, ...($this->auditIgnored ?? [])];
        $redacted = [...$this->getHidden(), ...($this->auditRedacted ?? [])];
        $values = [];

        foreach ($keys as $key) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $values[$key] = in_array($key, $redacted, true)
                ? self::AUDIT_REDACTED
                : ($original ? $this->getRawOriginal($key) : $this->getAttributes()[$key] ?? null);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function writeAudit(string $event, array $before = [], array $after = []): void
    {
        Audit::record(Str::snake(class_basename($this)).'.'.$event, $this, $before, $after);
    }
}
