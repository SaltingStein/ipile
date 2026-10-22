<?php

namespace App\Ai\Tools;

use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The asker's own account: name, email, whether two-factor is on, roles and teams. Never passwords, codes,
 * secrets or tokens (the data policy refuses those columns anyway).
 */
class MyAccount extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Read the signed-in person\'s own account: name, email, whether the email is verified, whether two-factor sign-in is on, when they joined, their site roles and their teams with their role in each.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        return [
            'name' => $this->user->display_name,
            'email' => $this->user->email,
            'email_verified' => $this->user->hasVerifiedEmail(),
            'two_factor_sign_in' => $this->user->hasEnabledTwoFactorAuthentication() ? 'on' : 'off',
            'member_since' => $this->user->created_at?->toFormattedDateString(),
            'site_roles' => $this->user->getRoleNames()->all(),
            'current_team' => $this->user->currentTeam?->name,
            'teams' => $this->user->allTeams()->map(fn (Team $team) => [
                'name' => $team->name,
                'role' => $team->isOwnedBy($this->user) ? 'owner' : ($team->roleOf($this->user)?->value ?? 'member'),
            ])->all(),
            'security_settings_page' => route('security.show'),
        ];
    }
}
