<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switch the team the user is currently working in.
 */
class CurrentTeamController extends Controller
{
    /**
     * Switch teams. A team the user does not belong to returns 404.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer'],
        ]);

        $team = Team::query()->find($validated['team_id']);

        abort_unless($team !== null && $request->user()->switchTeam($team), 404);

        return back()->with('status', __('Switched to :team.', ['team' => $team->name]));
    }
}
