<?php

namespace Tests\Feature\Admin;

use App\Models\Integration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GoogleAnalyticsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_analytics_has_its_own_settings_page_under_analytics(): void
    {
        $response = $this->actingAs($this->integrationsManager())->get(route('admin.integrations.show', 'google_analytics'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/IntegrationProvider')
            ->where('groupLabel', 'Analytics')
            ->where('provider.label', 'Google Analytics 4')
            ->where('provider.can_test', false)
            ->where('provider.fields.0.name', 'measurement_id'));
    }

    public function test_measurement_id_is_saved_and_enabled(): void
    {
        $this->actingAs($this->integrationsManager())
            ->put(route('admin.integrations.update', 'google_analytics'), ['is_enabled' => true, 'config' => ['measurement_id' => 'G-ABC123XYZ9']])
            ->assertSessionHasNoErrors();

        $integration = Integration::query()->where('provider', 'google_analytics')->sole();
        $this->assertTrue($integration->is_enabled);
        $this->assertSame('analytics', $integration->type);
        $this->assertSame('G-ABC123XYZ9', $integration->config['measurement_id']);
    }

    public function test_malformed_measurement_id_is_rejected(): void
    {
        $this->actingAs($this->integrationsManager())
            ->put(route('admin.integrations.update', 'google_analytics'), ['is_enabled' => true, 'config' => ['measurement_id' => 'UA-12345-1']])
            ->assertSessionHasErrors('config.measurement_id');

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_measurement_id_is_shared_with_pages_only_while_enabled(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('analytics.google_measurement_id', null));

        $integration = new Integration(['provider' => 'google_analytics', 'type' => 'analytics', 'is_enabled' => true]);
        $integration->config = ['measurement_id' => 'G-ABC123XYZ9'];
        $integration->save();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('analytics.google_measurement_id', 'G-ABC123XYZ9'));

        $integration->update(['is_enabled' => false]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('analytics.google_measurement_id', null));
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
