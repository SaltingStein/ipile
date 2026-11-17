<?php

namespace App\Http\Controllers;

use App\Services\Auth\AuthenticationSettings;
use App\Services\Campaigns\CampaignPresenter;
use App\Services\Campaigns\CampaignSettings;
use App\Services\MarketingSettings;
use App\Services\ThemeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the community marketing page when enabled, otherwise the current campaign or theme homepage.
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

        $marketing = app(MarketingSettings::class);

        if ($marketing->enabled()) {
            return app(MarketingController::class)($marketing);
        }

        $campaign = $campaigns->homePage();

        if ($campaign !== null) {
            return app(CampaignController::class)->render($request, $campaign, $presenter);
        }

        return $this->landing($themes->landingLayout());
    }

    private function landing(string $layout): Response
    {
        $settings = app(AuthenticationSettings::class);

        return Inertia::render("Landing/{$layout}", [
            'layout' => $layout,
            // Optional wording from Admin → Sign-in & 2FA; the form itself only shows while guest access is on.
            'guestStart' => ['buttonLabel' => $settings->guestButtonLabel(), 'fieldLabel' => $settings->guestFieldLabel()],
        ]);
    }
}
