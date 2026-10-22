<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Services\Teams\TeamManager;
use App\Services\Teams\TeamRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browser pages for listing, creating, viewing, renaming and deleting teams.
 */
class TeamController extends Controller
{
    /**
     * List the user's teams with a form to create one.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Teams/Index', [
            'teams' => TeamResource::collection($request->user()->allTeams())->resolve($request),
        ]);
    }

    /**
     * Create a team owned by the user and switch to it.
     */
    public function store(Request $request, TeamManager $teams): RedirectResponse
    {
        $team = $teams->create($request->user(), $request->validate($this->rules()));

        return redirect()->route('teams.show', $team)->with('status', __('Team created.'));
    }

    /**
     * Team settings: details, members, invitations.
     */
    public function show(Request $request, Team $team): Response
    {
        Gate::authorize('view', $team);

        $team->load(['owner', 'users', 'invitations']);

        return Inertia::render('Teams/Show', [
            'team' => (new TeamResource($team))->resolve($request),
            'roles' => TeamRole::options(),
        ]);
    }

    /**
     * Rename the team or change its description.
     */
    public function update(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team->update($request->validate($this->rules()));

        return back()->with('status', __('Team details saved.'));
    }

    /**
     * Delete the team. Owner only.
     */
    public function destroy(Team $team, TeamManager $teams): RedirectResponse
    {
        Gate::authorize('delete', $team);

        $teams->delete($team);

        return redirect()->route('teams.index')->with('status', __('Team deleted.'));
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
