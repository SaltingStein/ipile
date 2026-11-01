<?php

namespace App\Services\Assistant;

use App\Ai\Tools\AssistantTool;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Discovers insights and owns every query. An insight declares aggregates; it never receives an unscoped builder.
 */
class InsightRegistry
{
    public const ROW_LIMIT = 50;

    public function __construct(private AssistantSettings $settings) {}

    /** @return array<string, AggregateInsight> */
    public function discovered(): array
    {
        $insights = [];

        foreach (glob(app_path('Ai/Insights/*.php')) ?: [] as $file) {
            $class = 'App\\Ai\\Insights\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! is_subclass_of($class, AggregateInsight::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $insight = app($class);
            $this->validate($insight);

            if (isset($insights[$insight->key()])) {
                throw new InvalidArgumentException('Duplicate insight key: '.$insight->key());
            }

            $insights[$insight->key()] = $insight;
        }

        return $insights;
    }

    /** @return list<array{key: string, label: string, permission: ?string, scope: string}> */
    public function definitions(): array
    {
        return array_values(array_map(fn (AggregateInsight $insight): array => [
            'key' => $insight->key(), 'label' => $insight->label(),
            'permission' => $insight->permission(), 'scope' => $insight->scope(),
        ], $this->discovered()));
    }

    /** @return list<AggregateInsight> */
    public function for(User $user): array
    {
        return array_values(array_map(
            fn (AggregateInsight $insight): AggregateInsight => app($insight::class, ['user' => $user]),
            array_filter($this->discovered(), fn (AggregateInsight $insight): bool => $this->allowed($insight, $user)),
        ));
    }

    private function allowed(AggregateInsight $insight, ?User $user): bool
    {
        if ($user === null || $user->is_guest || $user->isSuspended()
            || ! $this->settings->enabled()
            || ! $this->settings->staffModeEnabled(AssistantIntent::Analytics)
            || ! $user->can('use_assistant_analytics')
            || ! in_array($insight->key(), $this->settings->insights(), true)
            || ($insight->permission() !== null && ! $user->can($insight->permission()))) {
            return false;
        }

        if ($insight->scope() === 'team') {
            $team = $this->team($user);

            return $team !== null && $team->hasUser($user)
                && Gate::forUser($user)->allows($insight->teamAbility(), $team);
        }

        return true;
    }

    private function team(User $user): ?Team
    {
        return $user->current_team_id === null ? null : Team::query()->find($user->current_team_id);
    }

    public function read(AggregateInsight $insight, ?User $user): string
    {
        $this->validate($insight);
        $user = $user === null ? null : User::query()->find($user->getKey());

        if (! $this->allowed($insight, $user)) {
            return 'That insight is not available to this person. Do not guess it.';
        }

        try {
            $result = AssistantDataPolicy::run(function () use ($insight, $user): array {
                if ($insight->summaryKey() !== null) {
                    $summary = DB::table('assistant_insight_summaries')->where('key', $insight->summaryKey())->first();

                    if ($summary === null || now()->subMinutes(15)->greaterThan($summary->refreshed_at)) {
                        return ['available' => false, 'message' => 'The scheduled aggregate summary is not ready or is stale.'];
                    }

                    return ['as_of' => $summary->refreshed_at, 'period_days' => 30, 'values' => json_decode($summary->values, true)];
                }

                $query = DB::table($insight->table())->whereBetween($insight->periodColumn(), [now()->subDays(30), now()]);

                if ($insight->scope() === 'team') {
                    $query->where('team_id', $user->current_team_id);
                }

                foreach ($insight->filters() as $column => $value) {
                    $query->where($column, $value);
                }

                $grammar = $query->getGrammar();
                $query->selectRaw('COUNT(*) AS total');

                if ($insight->sumColumn() !== null) {
                    $query->selectRaw('SUM('.$grammar->wrap($insight->sumColumn()).') AS amount');
                }

                foreach ($insight->groupBy() as $column) {
                    $query->addSelect($column)->groupBy($column)->orderBy($column);
                }

                if ($insight->trend()) {
                    $day = 'DATE('.$grammar->wrap($insight->periodColumn()).')';
                    $query->selectRaw($day.' AS day')->groupByRaw($day)->orderBy('day');
                }

                $rows = $query->limit(self::ROW_LIMIT + 1)->get();

                return [
                    'as_of' => now()->toIso8601String(), 'period_days' => 30,
                    'truncated' => $rows->count() > self::ROW_LIMIT,
                    'values' => $rows->take(self::ROW_LIMIT)->map(fn (object $row): array => (array) $row)->all(),
                ];
            });
        } catch (BlockedDataAccess $exception) {
            report($exception);

            return AssistantTool::NOT_AVAILABLE;
        }

        return (string) json_encode(['insight' => $insight->key(), 'scope' => $insight->scope(), ...$result], JSON_UNESCAPED_UNICODE);
    }

    private function validate(AggregateInsight $insight): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $insight->key()) !== 1
            || ! in_array($insight->scope(), ['platform', 'team'], true)
            || ($insight->permission() === null && ! $insight->safeForAll())
            || $insight->permission() === '') {
            throw new InvalidArgumentException('An insight needs a unique dotted key, a valid scope and an explicit permission or safe-for-all declaration.');
        }

        foreach ([$insight->table(), $insight->periodColumn(), ...$insight->groupBy(), ...array_keys($insight->filters()), ...($insight->sumColumn() === null ? [] : [$insight->sumColumn()])] as $column) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
                throw new InvalidArgumentException('Insight tables and columns must be plain identifiers.');
            }
        }

        foreach ($insight->groupBy() as $column) {
            if (preg_match('/(^|_)(id|name|email|phone|ip|address|secret|password|token|body|message)(_|$)/', $column) === 1) {
                throw new InvalidArgumentException('Insight groups cannot expose personal details, identifiers or secrets.');
            }
        }

        if ($insight->summaryKey() !== null && ! in_array($insight->summaryKey(), ['sms.delivery', 'security.incidents'], true)) {
            throw new InvalidArgumentException('Unknown built-in aggregate summary.');
        }

        if ($insight->summaryKey() !== null && $insight->scope() !== 'platform') {
            throw new InvalidArgumentException('Built-in summaries are platform-scoped.');
        }

        // Team insights are limited with `where team_id = current team`; without the column the query would fail mid-answer.
        if ($insight->scope() === 'team' && ! Schema::hasColumn($insight->table(), 'team_id')) {
            throw new InvalidArgumentException("Team insights need a team_id column; [{$insight->table()}] has none. Use the platform scope instead.");
        }

        $summaryPermission = match ($insight->summaryKey()) {
            'sms.delivery' => 'manage_integrations',
            'security.incidents' => 'view_security',
            default => null,
        };

        if ($summaryPermission !== null && ($insight->permission() !== $summaryPermission || $insight->key() !== $insight->summaryKey())) {
            throw new InvalidArgumentException('Built-in summaries keep their original key and data permission.');
        }
    }
}
