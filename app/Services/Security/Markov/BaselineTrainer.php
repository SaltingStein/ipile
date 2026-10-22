<?php

namespace App\Services\Security\Markov;

use App\Models\SecurityBaseline;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\SecuritySettings;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the Markov baselines from the event log (`php artisan security:train`, scheduled daily).
 *
 * Each account's events are split into sessions wherever there is a gap of {@see self::SESSION_GAP_MINUTES};
 * every session starts from {@see SequenceAnomalyDetector::START}. Events in incidents an administrator
 * resolved as "confirmed" are left out, so an attacker's behaviour is never learned as normal.
 */
class BaselineTrainer
{
    public const SESSION_GAP_MINUTES = 30;

    /** Accounts with fewer transitions than this get no personal baseline and fall back to their role. */
    public const MIN_USER_TRANSITIONS = 20;

    public function __construct(private SecuritySettings $settings) {}

    /**
     * @return array{events: int, scopes: int}
     */
    public function train(): array
    {
        $cohorts = $this->cohortsByUser();
        $counts = [];
        $userTransitions = [];
        $eventCount = 0;

        $events = SecurityEvent::query()
            ->whereNotNull('user_id')
            ->where('occurred_at', '>=', now()->subDays($this->settings->retentionDays()))
            ->where(fn ($query) => $query
                ->whereNull('security_incident_id')
                ->orWhereNotIn('security_incident_id', SecurityIncident::query()->where('resolution', 'confirmed')->select('id')))
            ->select(['id', 'user_id', 'type', 'occurred_at'])
            ->lazyById(1000);

        // Events stream in id order; each account's sequence is put in time order below.
        $sequences = [];
        foreach ($events as $event) {
            $sequences[$event->user_id][] = [$event->type, $event->occurred_at->getTimestamp()];
            $eventCount++;
        }

        foreach ($sequences as $userId => $sequence) {
            usort($sequence, fn (array $a, array $b) => $a[1] <=> $b[1]);
            $previousType = SequenceAnomalyDetector::START;
            $previousAt = null;

            foreach ($sequence as [$type, $timestamp]) {
                if ($previousAt !== null && $timestamp - $previousAt > self::SESSION_GAP_MINUTES * 60) {
                    $previousType = SequenceAnomalyDetector::START;
                }

                foreach ([BaselineStore::GLOBAL_SCOPE, $cohorts[$userId] ?? BaselineStore::cohortScope([]), BaselineStore::userScope($userId)] as $scope) {
                    $counts[$scope][$previousType][$type] = ($counts[$scope][$previousType][$type] ?? 0) + 1;
                }

                $userTransitions[$userId] = ($userTransitions[$userId] ?? 0) + 1;
                $previousType = $type;
                $previousAt = $timestamp;
            }
        }

        foreach ($userTransitions as $userId => $transitions) {
            if ($transitions < self::MIN_USER_TRANSITIONS) {
                unset($counts[BaselineStore::userScope($userId)]);
            }
        }

        $this->store($counts);
        $this->settings->markTrained();

        return ['events' => $eventCount, 'scopes' => count($counts)];
    }

    /**
     * @param  array<string, array<string, array<string, int>>>  $counts
     */
    private function store(array $counts): void
    {
        DB::transaction(function () use ($counts): void {
            SecurityBaseline::query()->whereNotIn('scope', array_keys($counts) ?: [''])->delete();

            foreach ($counts as $scope => $transitions) {
                SecurityBaseline::query()->updateOrCreate(['scope' => $scope], [
                    'transitions' => $transitions,
                    'transition_count' => array_sum(array_map('array_sum', $transitions)),
                    'trained_at' => now(),
                ]);
            }
        });
    }

    /**
     * user id => cohort scope, read in one query from the permission tables.
     *
     * @return array<int, string>
     */
    private function cohortsByUser(): array
    {
        $pivot = config('permission.table_names.model_has_roles');
        $roles = config('permission.table_names.roles');

        $assignments = DB::table($pivot)
            ->join($roles, "{$roles}.id", '=', "{$pivot}.".(config('permission.column_names.role_pivot_key') ?? 'role_id'))
            ->where("{$pivot}.model_type", (new User)->getMorphClass())
            ->get(["{$pivot}.".(config('permission.column_names.model_morph_key') ?? 'model_id').' as user_id', "{$roles}.name"]);

        $byUser = [];
        foreach ($assignments as $role) {
            $byUser[(int) $role->user_id][] = $role->name;
        }

        return array_map(fn (array $names) => BaselineStore::cohortScope($names), $byUser);
    }
}
