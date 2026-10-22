<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Blog\BlogSettings;
use App\Services\NavigationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The public site menu (Admin → Menus) and the "Add to menu" action used by the form builder.
 */
class SiteMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_manager_saves_the_site_menu_and_visitors_see_it(): void
    {
        $items = [['label' => 'Register', 'href' => '/f/summit'], ['label' => 'About', 'href' => '/about-boilerplate']];

        $this->actingAs($this->manager())->put(route('admin.navigation.site.update'), ['site_items' => $items])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Site menu saved.');

        auth()->logout();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('siteMenu', $items));
    }

    public function test_site_menu_rejects_external_links(): void
    {
        $this->actingAs($this->manager())->put(route('admin.navigation.site.update'), ['site_items' => [['label' => 'Evil', 'href' => 'https://evil.example']]])
            ->assertSessionHasErrors('site_items.0.href');

        $this->assertSame([], app(NavigationSettings::class)->siteItems());
    }

    public function test_blog_joins_the_site_menu_only_while_it_is_on(): void
    {
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('siteMenu', []));

        app(BlogSettings::class)->update(true, 'News', null);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('siteMenu', [['label' => 'News', 'href' => '/blog']]));
    }

    public function test_add_link_appends_once_to_the_chosen_menu(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.navigation.links.store'), ['menu' => 'site', 'label' => 'Summit', 'href' => '/f/summit'])
            ->assertSessionHas('status', 'Added to the site menu.');
        $this->actingAs($manager)->post(route('admin.navigation.links.store'), ['menu' => 'site', 'label' => 'Summit again', 'href' => '/f/summit'])
            ->assertSessionHas('status', 'Already in the site menu.');
        $this->actingAs($manager)->post(route('admin.navigation.links.store'), ['menu' => 'workspace', 'label' => 'Summit', 'href' => '/f/summit'])
            ->assertSessionHas('status', 'Added to the workspace sidebar.');

        $navigation = app(NavigationSettings::class);
        $this->assertSame([['label' => 'Summit', 'href' => '/f/summit']], $navigation->siteItems());
        $this->assertSame([['label' => 'Summit', 'href' => '/f/summit', 'permission' => null]], $navigation->items());
    }

    public function test_add_link_refuses_a_full_menu_and_unsafe_paths(): void
    {
        $manager = $this->manager();
        app(NavigationSettings::class)->updateSiteItems(array_map(fn (int $index) => ['label' => "Link {$index}", 'href' => "/page-{$index}"], range(1, 20)));

        $this->actingAs($manager)->post(route('admin.navigation.links.store'), ['menu' => 'site', 'label' => 'One more', 'href' => '/f/summit'])
            ->assertSessionHasErrors('menu');
        $this->actingAs($manager)->post(route('admin.navigation.links.store'), ['menu' => 'workspace', 'label' => 'Evil', 'href' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('href');

        $this->assertCount(20, app(NavigationSettings::class)->siteItems());
    }

    public function test_people_without_manage_settings_cannot_change_menus(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_forms');

        $this->actingAs($user)->post(route('admin.navigation.links.store'), ['menu' => 'site', 'label' => 'Summit', 'href' => '/f/summit'])->assertForbidden();
        $this->actingAs($user)->put(route('admin.navigation.site.update'), ['site_items' => []])->assertForbidden();
        $this->assertSame([], app(NavigationSettings::class)->siteItems());
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
