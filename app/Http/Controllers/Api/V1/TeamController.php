<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Services\Teams\TeamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/teams — the token owner's teams.
 */
class TeamController extends Controller
{
    /**
     * Teams the user owns or belongs to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TeamResource::collection($request->user()->allTeams());
    }

    /**
     * Create a team owned by the user (201) and make it their current team.
     */
    public function store(Request $request, TeamManager $teams): JsonResponse
    {
        $team = $teams->create($request->user(), $request->validate($this->rules()));

        return (new TeamResource($team->load(['owner', 'users', 'invitations'])))->response()->setStatusCode(201);
    }

    /**
     * One team with members (and invitations for the owner and admins). Non-members get 404.
     */
    public function show(Team $team): TeamResource
    {
        Gate::authorize('view', $team);

        return new TeamResource($team->load(['owner', 'users', 'invitations']));
    }

    /**
     * Rename the team. Owner and admins only.
     */
    public function update(Request $request, Team $team): TeamResource
    {
        Gate::authorize('update', $team);

        $team->update($request->validate($this->rules()));

        return new TeamResource($team->load(['owner', 'users', 'invitations']));
    }

    /**
     * Delete the team. Owner only.
     */
    public function destroy(Team $team, TeamManager $teams): Response
    {
        Gate::authorize('delete', $team);

        $teams->delete($team);

        return response()->noContent();
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
