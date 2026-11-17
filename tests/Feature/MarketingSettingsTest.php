<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\MarketingSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MarketingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_marketing_settings(): void
    {
        $this->actingAs($this->manager())->get(route('admin.marketing.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Marketing')
                ->where('enabled', false)
                ->where('repositoryUrl', 'https://github.com/SaltingStein/ipile'));
    }

    public function test_manager_can_enable_marketing_and_set_the_public_repository(): void
    {
        $this->actingAs($this->manager())->from(route('admin.marketing.edit'))
            ->put(route('admin.marketing.update'), ['enabled' => true, 'repository_url' => 'https://github.com/community/foundation'])
            ->assertRedirect(route('admin.marketing.edit'))
            ->assertSessionHas('status', 'Marketing page settings saved.');

        $this->assertDatabaseHas('settings', ['key' => MarketingSettings::ENABLED, 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => MarketingSettings::REPOSITORY, 'value' => 'https://github.com/community/foundation']);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('Marketing')
            ->where('repositoryUrl', 'https://github.com/community/foundation'));
    }

    public function test_manager_can_disable_marketing_after_it_has_been_loaded(): void
    {
        $manager = $this->manager();
        Setting::store(MarketingSettings::ENABLED, '1');
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('Marketing'));

        $this->actingAs($manager)->put(route('admin.marketing.update'), [
            'enabled' => false, 'repository_url' => 'https://github.com/SaltingStein/ipile',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => MarketingSettings::ENABLED, 'value' => '0']);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('Landing/LayoutAlpha'));
        $this->get(route('marketing'))->assertNotFound();
    }

    public function test_visitors_must_sign_in_to_manage_marketing(): void
    {
        $this->get(route('admin.marketing.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.marketing.update'), [])->assertRedirect(route('login'));
    }

    public function test_members_cannot_view_or_change_marketing_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('admin.marketing.edit'))->assertForbidden();
        $this->put(route('admin.marketing.update'), [
            'enabled' => true, 'repository_url' => 'https://github.com/other/repo',
        ])->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => MarketingSettings::ENABLED]);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['https://example.com/owner/repository'])]
    #[TestWith(['https://github.com/owner'])]
    #[TestWith(['http://github.com/owner/repository'])]
    public function test_invalid_repository_is_rejected_without_enabling_marketing(string $url): void
    {
        $this->actingAs($this->manager())->put(route('admin.marketing.update'), [
            'enabled' => true, 'repository_url' => $url,
        ])->assertSessionHasErrors('repository_url');

        $this->assertDatabaseMissing('settings', ['key' => MarketingSettings::ENABLED]);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->manager())->put(route('admin.marketing.update'), [])
            ->assertSessionHasErrors(['enabled', 'repository_url']);

        $this->assertDatabaseMissing('settings', ['key' => MarketingSettings::ENABLED]);
    }

    public function test_invalid_toggle_is_rejected(): void
    {
        $this->actingAs($this->manager())->put(route('admin.marketing.update'), [
            'enabled' => 'maybe', 'repository_url' => 'https://github.com/SaltingStein/ipile',
        ])->assertSessionHasErrors('enabled');

        $this->assertDatabaseMissing('settings', ['key' => MarketingSettings::ENABLED]);
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
