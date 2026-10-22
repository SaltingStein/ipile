<?php

namespace App\Services\Campaigns;

/**
 * Drafts are only visible to people who manage campaigns; published pages are open to everyone.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
