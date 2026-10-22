<?php

namespace App\Ai\Tools;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The teams the asker belongs to, with members' names and roles: the same as the team page shows them.
 * Other teams, and members' emails, are not included.
 */
class MyTeams extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Read the teams the signed-in person owns or belongs to: each team\'s name, owner, their own role, and the members\' names and roles. Only their own teams; never other teams or anyone\'s contact details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        $teams = $this->user->allTeams();

        if ($teams->isEmpty()) {
            return 'This person is not in any team yet.';
        }

        return $teams->map(function (Team $team) {
            $owner = $team->owner()->first(['id', 'name', 'nickname']);

            return [
                'team' => $team->name,
                'your_role' => $team->isOwnedBy($this->user) ? 'owner' : ($team->roleOf($this->user)?->value ?? 'member'),
                'owner' => $owner === null ? null : $this->nameOf($owner),
                'members' => $team->users()->orderBy('users.name')->limit(100)->get(['users.id', 'users.name', 'users.nickname'])
                    ->map(fn (User $member) => ['name' => $this->nameOf($member), 'role' => $member->pivot->role])
                    ->all(),
            ];
        })->all();
    }

    private function nameOf(User $user): string
    {
        return $user->name ?: $user->nickname ?: 'Team member';
    }
}
