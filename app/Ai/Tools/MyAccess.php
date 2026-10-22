<?php

namespace App\Ai\Tools;

use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * What the asker may do on the site: site roles, permissions, whether two-factor is on and their role in each team.
 * No names, emails or other contact details, so it can be shared with a third-party assistant (see WidgetDataTools).
 */
class MyAccess extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Read what the signed-in person may do on the site: their site roles, their permissions, whether two-factor sign-in is on, and their role in each of their teams. No names or contact details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        return [
            'site_roles' => $this->user->getRoleNames()->all(),
            'permissions' => $this->user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'two_factor_sign_in' => $this->user->hasEnabledTwoFactorAuthentication() ? 'on' : 'off',
            'teams' => $this->user->allTeams()->map(fn (Team $team) => [
                'team' => $team->name,
                'role' => $team->isOwnedBy($this->user) ? 'owner' : ($team->roleOf($this->user)?->value ?? 'member'),
            ])->values()->all(),
        ];
    }
}
