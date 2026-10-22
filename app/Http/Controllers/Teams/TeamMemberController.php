<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamManager;
use App\Services\Teams\TeamRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Change a member's role, remove a member, or leave a team.
 */
class TeamMemberController extends Controller
{
    /**
     * Change a member's role. Owner and admins only.
     */
    public function update(Request $request, Team $team, User $user, TeamManager $teams): RedirectResponse
    {
        Gate::authorize('manageMembers', $team);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        try {
            $teams->updateRole($team, $user, TeamRole::from($validated['role']));
        } catch (TeamException $exception) {
            return back()->withErrors(['role' => $exception->getMessage()]);
        }

        return back()->with('status', __('Role updated.'));
    }

    /**
     * Remove a member (owner and admins), or leave the team when removing yourself.
     */
    public function destroy(Request $request, Team $team, User $user, TeamManager $teams): RedirectResponse
    {
        $isLeaving = $user->is($request->user());

        Gate::authorize($isLeaving ? 'view' : 'manageMembers', $team);

        try {
            $teams->removeMember($team, $user);
        } catch (TeamException $exception) {
            return back()->withErrors(['member' => $exception->getMessage()]);
        }

        if ($isLeaving) {
            return redirect()->route('teams.index')->with('status', __('You left :team.', ['team' => $team->name]));
        }

        return back()->with('status', __('Member removed.'));
    }
}
