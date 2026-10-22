<?php

namespace Tests\Feature\Policies;

use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Expected result per ability for each relationship to the team: true = allowed,
     * 'forbidden' = 403, 'not_found' = 404 (outsiders must not learn the team exists).
     *
     * @return array<string, array{0: string, 1: string, 2: true|string}>
     */
    public static function permissionMatrix(): array
    {
        $expectations = [
            'owner' => ['view' => true, 'update' => true, 'manageMembers' => true, 'delete' => true, 'transferOwnership' => true],
            'admin' => ['view' => true, 'update' => true, 'manageMembers' => true, 'delete' => 'forbidden', 'transferOwnership' => 'forbidden'],
            'member' => ['view' => true, 'update' => 'forbidden', 'manageMembers' => 'forbidden', 'delete' => 'forbidden', 'transferOwnership' => 'forbidden'],
            'outsider' => ['view' => 'not_found', 'update' => 'not_found', 'manageMembers' => 'not_found', 'delete' => 'not_found', 'transferOwnership' => 'not_found'],
        ];

        $cases = [];
        foreach ($expectations as $relationship => $abilities) {
            foreach ($abilities as $ability => $expected) {
                $cases["{$relationship} {$ability}"] = [$relationship, $ability, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionMatrix')]
    public function test_team_permissions(string $relationship, string $ability, bool|string $expected): void
    {
        $team = Team::factory()->create();
        $user = match ($relationship) {
            'owner' => $team->owner,
            'admin' => $this->memberWithRole($team, TeamRole::Admin),
            'member' => $this->memberWithRole($team, TeamRole::Member),
            'outsider' => User::factory()->create(),
        };

        $response = (new TeamPolicy)->{$ability}($user, $team);

        if ($expected === true) {
            $this->assertTrue($response->allowed());

            return;
        }

        $this->assertTrue($response->denied());
        // A plain denial has no status and becomes a 403; outsiders get an explicit 404.
        $this->assertSame($expected === 'not_found' ? 404 : null, $response->status());
    }

    private function memberWithRole(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
