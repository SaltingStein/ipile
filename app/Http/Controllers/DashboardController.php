<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in home page. Make it yours: pass your product's data here and render it in resources/js/pages/Dashboard.tsx.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'stats' => [
                'teams' => $user->allTeams()->count(),
                'api_tokens' => $user->tokens()->count(),
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'member_since' => $user->created_at?->toDateString(),
            ],
            'showDeveloperChecklist' => app()->isLocal(),
        ]);
    }
}
