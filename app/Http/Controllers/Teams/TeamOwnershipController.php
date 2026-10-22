<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Hand a team to another member. Owner only.
 */
class TeamOwnershipController extends Controller
{
    /**
     * Make an existing member the owner; the previous owner stays on as an admin.
     */
    public function update(Request $request, Team $team, TeamManager $teams): RedirectResponse
    {
        Gate::authorize('transferOwnership', $team);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $newOwner = User::query()->find($validated['user_id']);

        try {
            abort_if($newOwner === null, 404);
            $teams->transferOwnership($team, $newOwner);
        } catch (TeamException $exception) {
            return back()->withErrors(['user_id' => $exception->getMessage()]);
        }

        return back()->with('status', __(':name now owns this team.', ['name' => $newOwner->display_name]));
    }
}
