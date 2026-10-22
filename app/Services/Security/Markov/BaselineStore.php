<?php

namespace App\Services\Security\Markov;

use App\Models\SecurityBaseline;
use App\Models\User;
use App\Services\Security\SecuritySettings;
use Illuminate\Support\Facades\Cache;

/**
 * Loads trained baselines for scoring. Cache keys include the last training time, so a new training run is
 * picked up without clearing anything.
 */
class BaselineStore
{
    public const GLOBAL_SCOPE = 'global';

    private const CACHE_SECONDS = 3600;

    public function __construct(private SecuritySettings $settings) {}

    /**
     * The matrices that apply to a user, least specific first.
     *
     * @return list<TransitionMatrix>
     */
    public function scopesFor(User $user): array
    {
        return [
            $this->matrix(self::GLOBAL_SCOPE),
            $this->matrix(self::cohortScope($user->getRoleNames()->all())),
            $this->matrix(self::userScope($user->getKey())),
        ];
    }

    public function matrix(string $scope): TransitionMatrix
    {
        $version = $this->settings->lastTrainedAt();

        if ($version === null) {
            return new TransitionMatrix;
        }

        $counts = Cache::remember(
            "security:baseline:{$version}:{$scope}",
            self::CACHE_SECONDS,
            fn () => SecurityBaseline::query()->where('scope', $scope)->value('transitions') ?? [],
        );

        return new TransitionMatrix(is_array($counts) ? $counts : (json_decode((string) $counts, true) ?: []));
    }

    /**
     * Users are grouped by their alphabetically first role, so each user belongs to exactly one cohort.
     *
     * @param  list<string>  $roles
     */
    public static function cohortScope(array $roles): string
    {
        sort($roles);

        return 'role:'.($roles[0] ?? 'none');
    }

    public static function userScope(int|string $userId): string
    {
        return 'user:'.$userId;
    }
}
