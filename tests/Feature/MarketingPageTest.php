<?php

namespace Tests\Feature;

use App\Models\CampaignPage;
use App\Models\Setting;
use App\Services\Campaigns\CampaignSettings;
use App\Services\MarketingSettings;
use App\Services\ThemeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_marketing_is_public_at_both_addresses(): void
    {
        Setting::store(MarketingSettings::ENABLED, '1');

        $this->get(route('marketing'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Marketing')
                ->where('repositoryUrl', 'https://github.com/SaltingStein/ipile'));
        $this->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Marketing'));
    }

    public function test_disabled_marketing_is_hidden_and_preserves_the_selected_theme(): void
    {
        Setting::store(MarketingSettings::ENABLED, '0');
        Setting::store(ThemeSettings::KEY, 'minimal');

        $this->get(route('marketing'))->assertNotFound();
        $this->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/LayoutGamma'));
    }

    public function test_existing_installations_keep_their_homepage_until_marketing_is_enabled(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/LayoutAlpha'));
        $this->get(route('marketing'))->assertNotFound();
    }

    public function test_marketing_takes_priority_and_disabling_it_restores_the_campaign(): void
    {
        $campaign = CampaignPage::factory()->create();
        app(CampaignSettings::class)->setHomePage($campaign);
        Setting::store(MarketingSettings::ENABLED, '1');

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('Marketing'));

        Setting::store(MarketingSettings::ENABLED, '0');

        $this->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Campaigns/Show')->where('page.slug', $campaign->slug));
    }
}
