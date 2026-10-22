<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamManager;
use App\Services\Teams\TeamRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Team membership over the API: invitations, roles, removal, ownership and the current team.
 * Business rules live in {@see TeamManager}; their messages are returned as 422 validation errors.
 */
class TeamMembershipController extends Controller
{
    public function __construct(private TeamManager $teams) {}

    /**
     * POST /api/v1/teams/{team}/invitations
     */
    public function invite(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('manageMembers', $team);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $invitation = $this->attempt('email', fn () => $this->teams->invite($team, $request->user(), $validated['email'], TeamRole::from($validated['role'])));

        return response()->json(['data' => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]], 201);
    }

    /**
     * DELETE /api/v1/teams/{team}/invitations/{invitation}
     */
    public function cancelInvitation(Team $team, TeamInvitation $invitation): Response
    {
        Gate::authorize('manageMembers', $team);

        $invitation->delete();

        return response()->noContent();
    }

    /**
     * POST /api/v1/team-invitations/{invitation}/accept — the account's verified email must match the invitation.
     */
    public function acceptInvitation(Request $request, TeamInvitation $invitation): TeamResource
    {
        $team = $this->attempt('invitation', fn () => $this->teams->acceptInvitation($invitation, $request->user()));

        return new TeamResource($team->load(['owner', 'users']));
    }

    /**
     * PUT /api/v1/teams/{team}/members/{user}
     */
    public function updateRole(Request $request, Team $team, User $user): Response
    {
        Gate::authorize('manageMembers', $team);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $this->attempt('role', fn () => $this->teams->updateRole($team, $user, TeamRole::from($validated['role'])));

        return response()->noContent();
    }

    /**
     * DELETE /api/v1/teams/{team}/members/{user} — removing yourself leaves the team.
     */
    public function removeMember(Request $request, Team $team, User $user): Response
    {
        Gate::authorize($user->is($request->user()) ? 'view' : 'manageMembers', $team);

        $this->attempt('member', fn () => $this->teams->removeMember($team, $user));

        return response()->noContent();
    }

    /**
     * PUT /api/v1/teams/{team}/owner
     */
    public function transferOwnership(Request $request, Team $team): Response
    {
        Gate::authorize('transferOwnership', $team);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $newOwner = User::query()->findOrFail($validated['user_id']);

        $this->attempt('user_id', fn () => $this->teams->transferOwnership($team, $newOwner));

        return response()->noContent();
    }

    /**
     * PUT /api/v1/user/current-team
     */
    public function switchTeam(Request $request): Response
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer'],
        ]);

        $team = Team::query()->find($validated['team_id']);

        abort_unless($team !== null && $request->user()->switchTeam($team), 404);

        return response()->noContent();
    }

    /**
     * Run a team operation, turning a rule violation into a 422 on the given field.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private function attempt(string $field, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (TeamException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
