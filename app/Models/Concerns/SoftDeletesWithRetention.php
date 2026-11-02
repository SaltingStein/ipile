<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft delete, then purge permanently once the retention window has passed.
 *
 * Deleting hides the record (route model binding, queries and counts all skip it)
 * but keeps it recoverable with restore(). The scheduled `model:prune` command
 * force-deletes it after `retention.deleted_records_days`. Put clean-up that must
 * only happen for good, such as removing uploaded files, in the model's
 * pruning() method, not in the controller that deletes it.
 *
 * Records the law requires you to keep (payments, for example) should use
 * SoftDeletes alone, never this trait.
 */
trait SoftDeletesWithRetention
{
    use Prunable, SoftDeletes;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays((int) config('retention.deleted_records_days')));
    }
}
