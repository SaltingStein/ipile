<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamManager;
use App\Services\Teams\TeamRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Invite people by email, cancel invitations, and accept them from the emailed link.
 */
class TeamInvitationController extends Controller
{
    /**
     * Invite an email address. Owner and admins only.
     */
    public function store(Request $request, Team $team, TeamManager $teams): RedirectResponse
    {
        Gate::authorize('manageMembers', $team);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        try {
            $teams->invite($team, $request->user(), $validated['email'], TeamRole::from($validated['role']));
        } catch (TeamException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        return back()->with('status', __('Invitation sent to :email.', ['email' => $validated['email']]));
    }

    /**
     * Cancel a pending invitation. Owner and admins only.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $team);

        $invitation->delete();

        return back()->with('status', __('Invitation cancelled.'));
    }

    /**
     * Accept from the signed email link. Only the account with the invited email address can join.
     */
    public function accept(Request $request, TeamInvitation $invitation, TeamManager $teams): RedirectResponse
    {
        try {
            $team = $teams->acceptInvitation($invitation, $request->user());
        } catch (TeamException $exception) {
            return redirect()->route('teams.index')->withErrors(['invitation' => $exception->getMessage()]);
        }

        return redirect()->route('teams.show', $team)->with('status', __('You joined :team.', ['team' => $team->name]));
    }
}
