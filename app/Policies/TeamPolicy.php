<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a team. Non-members get a 404 so they cannot learn the team exists.
 */
class TeamPolicy
{
    /**
     * Owner and members may view the team and its member list.
     */
    public function view(User $user, Team $team): Response
    {
        return $team->hasUser($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Owner and admins may rename the team.
     */
    public function update(User $user, Team $team): Response
    {
        return $this->managerOnly($user, $team);
    }

    /**
     * Owner and admins may invite, remove members and change roles.
     */
    public function manageMembers(User $user, Team $team): Response
    {
        return $this->managerOnly($user, $team);
    }

    /**
     * Owner and admins may build the team's forms and read their responses.
     */
    public function manageForms(User $user, Team $team): Response
    {
        return $this->managerOnly($user, $team);
    }

    /**
     * Only the owner may delete the team.
     */
    public function delete(User $user, Team $team): Response
    {
        return $this->ownerOnly($user, $team);
    }

    /**
     * Only the owner may hand the team to someone else.
     */
    public function transferOwnership(User $user, Team $team): Response
    {
        return $this->ownerOnly($user, $team);
    }

    private function managerOnly(User $user, Team $team): Response
    {
        if (! $team->hasUser($user)) {
            return Response::denyAsNotFound();
        }

        return $team->canBeManagedBy($user)
            ? Response::allow()
            : Response::deny(__('Only the team owner and admins can do this.'));
    }

    private function ownerOnly(User $user, Team $team): Response
    {
        if (! $team->hasUser($user)) {
            return Response::denyAsNotFound();
        }

        return $team->isOwnedBy($user)
            ? Response::allow()
            : Response::deny(__('Only the team owner can do this.'));
    }
}
