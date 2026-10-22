<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\NavigationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_manager_can_open_editor_and_save_ordered_shared_links(): void
    {
        $manager = $this->manager();
        $items = [
            ['label' => 'Reports', 'href' => '/reports', 'permission' => null],
            ['label' => 'Billing', 'href' => '/billing', 'permission' => 'view_billing'],
        ];

        $this->actingAs($manager)->get(route('admin.navigation.edit'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Navigation')->where('items', []));

        $this->actingAs($manager)->from(route('admin.navigation.edit'))
            ->put(route('admin.navigation.update'), ['items' => $items])
            ->assertRedirect(route('admin.navigation.edit'))
            ->assertSessionHas('status', 'Sidebar menu saved.');

        $this->assertSame($items, json_decode(Setting::query()->where('key', NavigationSettings::KEY)->value('value'), true));
        $this->actingAs($manager)->get(route('admin.navigation.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('items', $items));
    }

    public function test_shared_links_respect_each_viewers_permissions(): void
    {
        $manager = $this->manager();
        $items = [
            ['label' => 'Reports', 'href' => '/reports', 'permission' => null],
            ['label' => 'Billing', 'href' => '/billing', 'permission' => 'view_billing'],
        ];
        $this->actingAs($manager)->put(route('admin.navigation.update'), ['items' => $items])->assertSessionHasNoErrors();

        $member = User::factory()->create();
        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('navigation', [$items[0]])->where('navigationSettingsUrl', null));

        $member->givePermissionTo('view_billing');
        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('navigation', $items));
    }

    public function test_super_admin_can_manage_and_see_permission_restricted_links(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('super_admin');
        $items = [['label' => 'Billing', 'href' => '/billing', 'permission' => 'view_billing']];

        $this->actingAs($admin)->put(route('admin.navigation.update'), ['items' => $items])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('navigation', $items)->where('navigationSettingsUrl', route('admin.navigation.edit')));
    }

    public function test_anonymous_visitors_cannot_open_or_update_shared_navigation(): void
    {
        $this->get(route('admin.navigation.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.navigation.update'), ['items' => []])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('settings', ['key' => NavigationSettings::KEY]);
    }

    public function test_members_and_guests_cannot_change_shared_navigation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach ([User::factory()->create()->assignRole('member'), User::factory()->guest()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.navigation.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('admin.navigation.update'), ['items' => []])->assertForbidden();
        }
        $this->assertDatabaseMissing('settings', ['key' => NavigationSettings::KEY]);
    }

    public function test_manager_can_remove_all_custom_links(): void
    {
        $manager = $this->manager();
        Setting::store(NavigationSettings::KEY, '[{"label":"Old","href":"/old","permission":null}]');

        $this->actingAs($manager)->put(route('admin.navigation.update'), ['items' => []])->assertSessionHasNoErrors();
        $this->assertSame([], app(NavigationSettings::class)->items());
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected_without_changing_saved_links(string $path): void
    {
        $this->actingAs($this->manager())->put(route('admin.navigation.update'), [
            'items' => [['label' => 'Unsafe', 'href' => $path, 'permission' => null]],
        ])->assertSessionHasErrors(['items.0.href' => 'Enter an internal path such as /reports. External URLs and scripts are not allowed.']);

        $this->assertDatabaseMissing('settings', ['key' => NavigationSettings::KEY]);
    }

    public static function unsafePaths(): array
    {
        return [
            'external URL' => ['https://example.com'],
            'protocol relative' => ['//example.com'],
            'javascript' => ['javascript:alert(1)'],
            'backslash' => ['/\\example.com'],
            'relative path' => ['reports'],
            'encoded separators' => ['/%2f%2fexample.com'],
        ];
    }

    #[DataProvider('invalidItems')]
    public function test_invalid_menu_items_are_rejected(array $items, string $field): void
    {
        $this->actingAs($this->manager())->put(route('admin.navigation.update'), ['items' => $items])
            ->assertSessionHasErrors($field);
        $this->assertDatabaseMissing('settings', ['key' => NavigationSettings::KEY]);
    }

    public static function invalidItems(): array
    {
        $valid = ['label' => 'Reports', 'href' => '/reports', 'permission' => null];

        return [
            'blank label' => [[array_replace($valid, ['label' => ''])], 'items.0.label'],
            'long label' => [[array_replace($valid, ['label' => str_repeat('a', 61)])], 'items.0.label'],
            'long path' => [[array_replace($valid, ['href' => '/'.str_repeat('a', 2048)])], 'items.0.href'],
            'unknown permission' => [[array_replace($valid, ['permission' => 'missing_permission'])], 'items.0.permission'],
            'duplicate path' => [[$valid, $valid], 'items.0.href'],
            'unexpected key' => [[array_replace($valid, ['is_admin' => true])], 'items.0'],
            'too many items' => [array_fill(0, 21, $valid), 'items'],
        ];
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
