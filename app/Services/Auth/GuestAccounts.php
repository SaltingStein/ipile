<?php

namespace App\Services\Auth;

use App\Listeners\RecordGuestReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping for guest accounts (started from the home page without signing up, see OnboardingController).
 *
 * A guest stays signed in through a "remember me" cookie, so `last_login_at` is refreshed whenever they come back
 * ({@see RecordGuestReturn}). A guest is inactive when they have not come back for the retention period
 * and have no recent session. Accounts that were upgraded to a full account are never guests and are never touched.
 */
class GuestAccounts
{
    /**
     * Delete guest accounts unused for at least $days days, with their sessions and tokens. Returns how many.
     */
    public function pruneInactive(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $recentSessions = Schema::hasTable('sessions')
            ? DB::table('sessions')->whereNotNull('user_id')->where('last_activity', '>=', $cutoff->getTimestamp())->select('user_id')
            : null;

        $deleted = 0;

        User::query()
            ->where('is_guest', true)
            ->where(fn ($query) => $query->where('last_login_at', '<', $cutoff)
                ->orWhere(fn ($query) => $query->whereNull('last_login_at')->where('created_at', '<', $cutoff)))
            ->when($recentSessions, fn ($query) => $query->whereNotIn('id', $recentSessions))
            ->chunkById(200, function ($guests) use (&$deleted): void {
                foreach ($guests as $guest) {
                    DB::transaction(function () use ($guest): void {
                        $guest->tokens()->delete();
                        DB::table('sessions')->where('user_id', $guest->getKey())->delete();
                        $guest->delete();
                    });
                    $deleted++;
                }
            });

        return $deleted;
    }
}
