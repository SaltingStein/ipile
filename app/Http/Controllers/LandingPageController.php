<?php

namespace App\Http\Controllers;

use App\Services\Campaigns\CampaignPresenter;
use App\Services\Campaigns\CampaignSettings;
use App\Services\ThemeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the landing layout of the theme chosen in Admin → Theme, or the campaign page chosen as the home
 * page in Admin → Campaigns while that page is live.
 */
class LandingPageController extends Controller
{
    /**
     * Render the current product landing layout for anonymous visitors.
     */
    public function index(Request $request, ThemeSettings $themes, CampaignSettings $campaigns, CampaignPresenter $presenter): Response
    {
        if (! Schema::hasTable('settings')) {
            return $this->landing(ThemeSettings::THEMES[ThemeSettings::DEFAULT]['landing']);
        }

        $campaign = $campaigns->homePage();

        if ($campaign !== null) {
            return app(CampaignController::class)->render($request, $campaign, $presenter);
        }

        return $this->landing($themes->landingLayout());
    }

    private function landing(string $layout): Response
    {
        return Inertia::render("Landing/{$layout}", [
            'layout' => $layout,
        ]);
    }
}
