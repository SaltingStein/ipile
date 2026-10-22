<?php

namespace App\Services\Auth;

use App\Http\Controllers\Settings\BrowserSessionController;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists and revokes a user's browser sessions. Only the `database` session driver stores sessions
 * in a queryable form, so other drivers report no sessions.
 */
class BrowserSessionRegistry
{
    /**
     * @return Collection<int, array{id: string, ip_address: ?string, user_agent: ?string, last_active_at: string, is_current: bool}>
     */
    public function forUser(User $user, string $currentSessionId): Collection
    {
        if (! $this->isSupported()) {
            return collect();
        }

        return $this->query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                'is_current' => $session->id === $currentSessionId,
            ]);
    }

    /**
     * Delete every stored session for the user except the current one. Callers must also invalidate
     * "remember me" cookies (see {@see BrowserSessionController}).
     */
    public function revokeOthers(User $user, string $currentSessionId): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $this->query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    /**
     * Delete every stored session for the user, including the current one (used when an account may be compromised).
     */
    public function revokeAll(User $user): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $this->query()->where('user_id', $user->getAuthIdentifier())->delete();
    }

    /**
     * Whether the configured session driver can be listed.
     */
    public function isSupported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Query the configured session table.
     */
    private function query(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }
}
