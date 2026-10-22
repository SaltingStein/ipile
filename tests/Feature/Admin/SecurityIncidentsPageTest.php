<?php

namespace Tests\Feature\Admin;

use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\Security;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers Admin → Security → Incidents and the `ipile:restore-user` recovery command.
 */
class SecurityIncidentsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_incidents_page_lists_incidents_for_security_staff_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $flagged = User::factory()->create();
        Security::recordFor($flagged, 'assistant.sensitive_request', ['category' => 'credentials']);
        $viewer = User::factory()->create()->givePermissionTo('view_security');

        $this->get(route('admin.security.incidents.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create()->givePermissionTo('manage_settings'))->get(route('admin.security.incidents.index'))->assertForbidden();

        $this->actingAs($viewer)->get(route('admin.security.incidents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SecurityIncidents')
                ->where('filter', 'active')
                ->where('counts.open', 1)
                ->where('counts.high_risk', 1)
                ->has('incidents.data', 1)
                ->where('incidents.data.0.user.email', $flagged->email));

        $this->actingAs($viewer)->get(route('admin.security.incidents.index', ['status' => 'resolved']))
            ->assertInertia(fn (Assert $page) => $page->where('filter', 'resolved')->has('incidents.data', 0));
        $this->actingAs($viewer)->get(route('admin.security.incidents.index', ['status' => 'everything']))->assertSessionHasErrors('status');
    }

    public function test_incident_page_links_back_to_the_incidents_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Security::recordFor(User::factory()->create(), 'assistant.sensitive_request');

        $this->actingAs(User::factory()->create()->givePermissionTo('view_security'))
            ->get(route('admin.security.incidents.show', SecurityIncident::query()->sole()))
            ->assertInertia(fn (Assert $page) => $page->where('urls.index', route('admin.security.incidents.index')));
    }

    public function test_restore_user_command_lifts_a_suspension(): void
    {
        $user = User::factory()->suspended()->create(['email' => 'ada@example.com']);

        $this->artisan('ipile:restore-user', ['email' => 'ada@example.com'])->assertSuccessful();
        $this->assertFalse($user->fresh()->isSuspended());

        $this->artisan('ipile:restore-user', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
