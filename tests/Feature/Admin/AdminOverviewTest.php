<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\LegalContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function actor(array $permissions): User
    {
        $this->seed([RolesAndPermissionsSeeder::class, LegalContentSeeder::class]);

        return User::factory()->create()->givePermissionTo($permissions);
    }

    public function test_overview_opens_for_anyone_who_can_reach_an_admin_page(): void
    {
        foreach (['manage_settings', 'edit_legal_content', 'publish_legal_content', 'manage_access', 'manage_integrations', 'view_security', 'manage_security'] as $permission) {
            $this->actingAs($this->actor([$permission]))->get(route('admin.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Overview'));
        }
    }

    public function test_overview_refuses_members_without_admin_permissions(): void
    {
        $this->actingAs($this->actor([]))->get(route('admin.index'))->assertForbidden();
    }

    public function test_old_site_settings_address_redirects_to_the_overview(): void
    {
        $this->actingAs($this->actor(['manage_settings']))->get('/admin/site-settings')
            ->assertStatus(301)->assertRedirect('/admin');
    }
}
