<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Security\Security;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum personal access tokens: creation with the dashboard lifetime, the idle timeout, listing and pruning.
 * Shared by the API (sign-in, token list) and the web "API tokens" page so both follow the same rules.
 */
class ApiTokens
{
    public function __construct(private AuthenticationSettings $settings) {}

    /**
     * Create a token. It expires after the dashboard lifetime, or sooner when `$expiresInDays` is shorter.
     */
    public function create(User $user, string $name, ?int $expiresInDays = null): NewAccessToken
    {
        $lifetimeDays = $this->settings->apiTokenLifetimeDays();
        $days = match (true) {
            $expiresInDays === null || $expiresInDays <= 0 => $lifetimeDays,
            $lifetimeDays === 0 => $expiresInDays,
            default => min($expiresInDays, $lifetimeDays),
        };

        $token = $user->createToken($name, ['*'], $days > 0 ? now()->addDays($days) : null);
        Security::recordFor($user, 'api.token_created');

        return $token;
    }

    /**
     * Whether a token has gone unused for longer than the dashboard idle timeout. Checked on every API request.
     */
    public function isIdle(PersonalAccessToken $token): bool
    {
        $idleDays = $this->settings->apiTokenIdleDays();
        $lastActivity = $token->last_used_at ?? $token->created_at;

        return $idleDays > 0 && $lastActivity !== null && $lastActivity->lt(now()->subDays($idleDays));
    }

    /**
     * The user's tokens, newest first, without the secret.
     *
     * @return list<array{id: int, name: string, last_used_at: ?string, created_at: ?string, expires_at: ?string, is_current: bool}>
     */
    public function summaries(User $user, ?int $currentTokenId = null): array
    {
        return $user->tokens()
            ->latest('id')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_current' => $token->id === $currentTokenId,
            ])
            ->all();
    }

    /**
     * Delete tokens that have gone unused for longer than the idle timeout. Expired tokens are removed by
     * `sanctum:prune-expired`.
     */
    public function pruneIdle(): int
    {
        $idleDays = $this->settings->apiTokenIdleDays();

        if ($idleDays === 0) {
            return 0;
        }

        $cutoff = CarbonImmutable::now()->subDays($idleDays);

        return PersonalAccessToken::query()
            ->where(fn ($query) => $query
                ->where('last_used_at', '<', $cutoff)
                ->orWhere(fn ($query) => $query->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
            ->delete();
    }
}
