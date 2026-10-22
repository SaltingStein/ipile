<?php

namespace App\Services\Campaigns;

use App\Models\CampaignPage;
use App\Models\Setting;

/**
 * Which campaign page, if any, replaces the landing page at /. The landing page comes back by itself while
 * that page is a draft, has ended or has been deleted.
 */
class CampaignSettings
{
    public const HOME_PAGE = 'campaigns.home_page_id';

    public function homePageId(): ?int
    {
        $id = Setting::valueOf(self::HOME_PAGE, '');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The page to show at / right now, or null for the normal landing page.
     */
    public function homePage(): ?CampaignPage
    {
        $id = $this->homePageId();
        $page = $id === null ? null : CampaignPage::query()->find($id);

        return $page !== null && $page->isPublished() && ! $page->hasEnded() ? $page : null;
    }

    public function setHomePage(?CampaignPage $page): void
    {
        Setting::store(self::HOME_PAGE, $page === null ? '' : (string) $page->id);
    }
}
