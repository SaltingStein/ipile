<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\ThemeSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ThemeSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_the_current_theme_and_options(): void
    {
        $this->actingAs($this->manager())->get(route('admin.theme.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Theme')
                ->where('currentTheme', 'fintech')
                ->has('themes', count(ThemeSettings::THEMES))
                ->where('themes.1.value', 'bold')
                ->has('themes.1.swatches', 4));
    }

    public function test_saved_theme_sets_the_landing_layout_and_site_wide_theme(): void
    {
        $this->actingAs($this->manager())->put(route('admin.theme.update'), ['theme' => 'minimal'])
            ->assertSessionHas('status', 'Theme saved.');

        $this->get(route('home'))
            ->assertSee('data-theme="minimal"', false)
            ->assertInertia(fn (Assert $page) => $page->component('Landing/LayoutGamma')->where('theme', 'minimal'));
        $this->get(route('admin.theme.edit'))->assertSee('data-theme="minimal"', false);
    }

    public function test_the_previous_landing_layout_setting_still_picks_its_theme(): void
    {
        Setting::store(ThemeSettings::LEGACY_LANDING_LAYOUT, 'LayoutBeta');

        $this->assertSame('bold', app(ThemeSettings::class)->current());
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('Landing/LayoutBeta'));
    }

    public function test_unknown_theme_is_rejected_and_unknown_stored_values_fall_back(): void
    {
        $this->actingAs($this->manager())->put(route('admin.theme.update'), ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');

        Setting::store(ThemeSettings::KEY, 'removed');

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('Landing/LayoutAlpha')->where('theme', 'fintech'));
    }

    public function test_members_cannot_change_the_theme(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('admin.theme.edit'))->assertForbidden();
        $this->actingAs($member)->put(route('admin.theme.update'), ['theme' => 'bold'])->assertForbidden();
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
