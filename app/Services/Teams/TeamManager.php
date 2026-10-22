<?php

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Services\Forms\FormMedia;
use App\Services\Notifications\Notify;
use App\Services\Security\Security;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Team operations shared by the web and API controllers. Callers authorize the acting user first
 * (see {@see TeamPolicy}); this class enforces the rules about the team's state.
 */
class TeamManager
{
    /**
     * Create a team owned by the user and make it their current team.
     *
     * @param  array{name: string, description?: ?string}  $attributes
     */
    public function create(User $owner, array $attributes): Team
    {
        return DB::transaction(function () use ($owner, $attributes): Team {
            $team = new Team($attributes);
            $team->owner()->associate($owner);
            $team->save();

            $owner->switchTeam($team);
            Security::recordFor($owner, 'team.created', ['team_id' => $team->getKey()]);

            return $team;
        });
    }

    /**
     * Delete the team. Members whose current team it was are left without a current team.
     */
    public function delete(Team $team): void
    {
        // The database removes the team's forms with it; their banner files are removed here.
        $mediaPaths = $team->forms()->whereNotNull('media_path')->pluck('media_path');

        DB::transaction(function () use ($team): void {
            User::query()->where('current_team_id', $team->getKey())->update(['current_team_id' => null]);

            $team->delete();
        });

        $mediaPaths->each(fn (string $path) => app(FormMedia::class)->deleteFile($path));

        Security::record('team.deleted', ['team_id' => $team->getKey()]);
    }

    /**
     * Invite an email address and send the invitation email.
     *
     * @throws TeamException
     */
    public function invite(Team $team, User $inviter, string $email, TeamRole $role): TeamInvitation
    {
        $email = Str::lower(trim($email));

        if ($team->hasUserWithEmail($email)) {
            throw new TeamException(__('This person is already on the team.'));
        }

        if ($team->invitations()->where('email', $email)->exists()) {
            throw new TeamException(__('This email address has already been invited.'));
        }

        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role->value,
            'invited_by' => $inviter->getKey(),
        ]);

        Notify::event('team.invitation')
            ->toEmail($email)
            ->with([
                'team' => $team->name,
                'inviter' => $inviter->display_name,
                'link' => $invitation->acceptUrl(),
                'expires_in_days' => TeamInvitation::LINK_LIFETIME_DAYS,
            ])
            ->send();

        Security::recordFor($inviter, 'team.member_invited', ['team_id' => $team->getKey(), 'role' => $role->value]);

        return $invitation;
    }

    /**
     * Join the team from an invitation. Only the account with the invited email address may accept.
     *
     * @throws TeamException
     */
    public function acceptInvitation(TeamInvitation $invitation, User $user): Team
    {
        if ($user->email === null || Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new TeamException(__('This invitation was sent to a different email address. Sign in with that address to accept it.'));
        }

        $team = $invitation->team;

        DB::transaction(function () use ($invitation, $user, $team): void {
            if (! $team->hasUser($user)) {
                $team->users()->attach($user, ['role' => $invitation->role]);
            }

            $invitation->delete();
        });

        $user->switchTeam($team);

        return $team;
    }

    /**
     * Change a member's role. The owner has no membership role and cannot be changed here.
     *
     * @throws TeamException
     */
    public function updateRole(Team $team, User $member, TeamRole $role): void
    {
        $this->ensureIsMember($team, $member);

        $team->users()->updateExistingPivot($member->getKey(), ['role' => $role->value]);

        Security::record('team.member_role_changed', ['team_id' => $team->getKey(), 'member_id' => $member->getKey(), 'role' => $role->value]);
    }

    /**
     * Remove a member (or let a member leave). The owner must transfer ownership or delete the team instead.
     *
     * @throws TeamException
     */
    public function removeMember(Team $team, User $member): void
    {
        $this->ensureIsMember($team, $member);

        DB::transaction(function () use ($team, $member): void {
            $team->users()->detach($member->getKey());

            if ($member->current_team_id === $team->getKey()) {
                $member->forceFill(['current_team_id' => null])->save();
            }
        });

        Security::record('team.member_removed', ['team_id' => $team->getKey(), 'member_id' => $member->getKey()]);
    }

    /**
     * Hand the team to an existing member. The previous owner stays on the team as an admin.
     *
     * @throws TeamException
     */
    public function transferOwnership(Team $team, User $newOwner): void
    {
        $this->ensureIsMember($team, $newOwner);

        DB::transaction(function () use ($team, $newOwner): void {
            $previousOwnerId = $team->owner_id;

            $team->users()->detach($newOwner->getKey());
            $team->owner()->associate($newOwner);
            $team->save();
            $team->users()->attach($previousOwnerId, ['role' => TeamRole::Admin->value]);
        });

        Security::record('team.ownership_transferred', ['team_id' => $team->getKey(), 'new_owner_id' => $newOwner->getKey()]);
    }

    /**
     * @throws TeamException
     */
    private function ensureIsMember(Team $team, User $user): void
    {
        if ($team->isOwnedBy($user)) {
            throw new TeamException(__('The team owner cannot be changed or removed this way. Transfer ownership first.'));
        }

        if ($team->roleOf($user) === null) {
            throw new TeamException(__('This person is not a member of the team.'));
        }
    }
}
