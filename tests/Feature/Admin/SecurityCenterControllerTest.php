<?php

namespace Tests\Feature\Admin;

use App\Jobs\AnalyzeSecurityIncident;
use App\Models\Integration;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityRiskState;
use App\Models\User;
use App\Services\Security\SecurityMode;
use App\Services\Security\SecuritySettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecurityCenterControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_members_cannot_open_the_security_centre(): void
    {
        $member = User::factory()->create()->assignRole('member');
        $incident = SecurityIncident::factory()->create();

        $this->actingAs($member)->get(route('admin.security.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.security.guide'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.security.incidents.show', $incident))->assertForbidden();
        $this->actingAs($member)->get(route('admin.security.settings.edit'))->assertForbidden();
    }

    public function test_admins_can_review_incidents_but_not_change_settings(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.security.guide'))->assertOk();
        $this->actingAs($admin)->get(route('admin.security.settings.edit'))->assertForbidden();
        $this->actingAs($admin)->put(route('admin.security.settings.update'), $this->settingsPayload(['mode' => 'off']))->assertForbidden();
        $this->assertSame(SecurityMode::Monitor, app(SecuritySettings::class)->mode());
    }

    public function test_incident_links_are_only_offered_to_users_who_can_open_incidents(): void
    {
        $settingsManager = User::factory()->create()->givePermissionTo('manage_security');
        $viewer = User::factory()->create()->givePermissionTo('view_security');
        $incident = SecurityIncident::factory()->create();

        $this->actingAs($settingsManager)->get(route('admin.security.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->where('canTriage', false));
        $this->get(route('admin.security.incidents.show', $incident))->assertForbidden();

        $this->actingAs($viewer)->get(route('admin.security.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('canTriage', true));
        $this->get(route('admin.security.incidents.show', $incident))->assertOk();
    }

    public function test_the_overview_lists_active_incidents_and_headline_numbers(): void
    {
        $user = User::factory()->create(['name' => 'Ngozi']);
        $active = SecurityIncident::factory()->for($user)->create(['risk_score' => 88]);
        SecurityIncident::factory()->resolved()->create();
        SecurityEvent::factory()->for($user)->risky(88)->create(['security_incident_id' => $active->id]);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.security.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SecurityCenter')
            ->where('mode.value', 'monitor')
            ->where('filter', 'active')
            ->where('stats.active_incidents', 1)
            ->where('stats.risky_events_24h', 1)
            ->has('incidents.data', 1)
            ->where('incidents.data.0.id', $active->id)
            ->where('incidents.data.0.user.name', 'Ngozi')
            ->has('recentEvents', 1)
            ->where('canManage', true));
    }

    public function test_the_incident_page_shows_the_timeline(): void
    {
        $incident = SecurityIncident::factory()->create();
        SecurityEvent::factory()->for($incident->user)->ofType('account.two_factor_disabled', 'account')->risky()->create([
            'security_incident_id' => $incident->id,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0',
            'signals' => [['key' => 'new_device', 'label' => 'New device', 'score' => 1, 'weight' => 0.35, 'detail' => null]],
        ]);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.security.incidents.show', $incident));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SecurityIncident')
            ->where('events.0.label', 'Two-factor turned off')
            ->where('events.0.device', 'Chrome on Windows')
            ->where('events.0.signals.0.key', 'new_device')
            ->where('ai.available', false));
    }

    public function test_resolving_an_incident_resets_the_account_posture(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $incident = SecurityIncident::factory()->create();
        SecurityRiskState::query()->create(['user_id' => $incident->user_id, 'belief' => [0, 0, 0.5, 0.5], 'score' => 78, 'level' => 'high', 'last_action' => 'step_up']);

        $this->actingAs($admin)->put(route('admin.security.incidents.update', $incident), [
            'status' => 'resolved',
            'resolution' => 'false_positive',
            'note' => 'Owner was travelling.',
        ])->assertSessionHas('status');

        $incident->refresh();
        $this->assertSame('resolved', $incident->status);
        $this->assertSame('false_positive', $incident->resolution);
        $this->assertSame($admin->id, $incident->resolved_by);
        $state = SecurityRiskState::query()->where('user_id', $incident->user_id)->sole();
        $this->assertSame(0, $state->score);
        $this->assertNull($state->last_action);
    }

    public function test_resolving_requires_an_outcome(): void
    {
        $incident = SecurityIncident::factory()->create();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.security.incidents.update', $incident), ['status' => 'resolved'])
            ->assertSessionHasErrors('resolution');
    }

    public function test_an_incident_can_be_acknowledged(): void
    {
        $incident = SecurityIncident::factory()->create();

        $this->actingAs($this->superAdmin())->put(route('admin.security.incidents.update', $incident), ['status' => 'acknowledged']);

        $this->assertSame('acknowledged', $incident->fresh()->status);
    }

    public function test_analysis_needs_the_anthropic_integration(): void
    {
        Queue::fake();
        $incident = SecurityIncident::factory()->create();

        $this->actingAs($this->superAdmin())->post(route('admin.security.incidents.analyze', $incident))->assertSessionHasErrors('analysis');

        $integration = new Integration(['provider' => 'anthropic', 'type' => 'ai', 'is_enabled' => true]);
        $integration->config = ['api_key' => 'sk-ant-test', 'model' => 'claude-opus-5-5'];
        $integration->save();

        $this->actingAs($this->superAdmin())->post(route('admin.security.incidents.analyze', $incident))->assertSessionHas('status');

        Queue::assertPushed(AnalyzeSecurityIncident::class, fn (AnalyzeSecurityIncident $job) => $job->incidentId === $incident->id);
        $this->assertSame(SecurityIncident::AI_PENDING, $incident->fresh()->ai_status);
    }

    public function test_settings_are_saved_and_the_change_is_audited(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.security.settings.update'), $this->settingsPayload([
            'mode' => 'enforce',
            'thresholds' => ['notify' => 50, 'step_up' => 70, 'revoke_sessions' => null, 'suspend' => null],
            'disabled_categories' => ['payments'],
            'retention_days' => 30,
        ]))->assertSessionHas('status', 'Monitoring settings saved.');

        $settings = app(SecuritySettings::class);
        $this->assertSame(SecurityMode::Enforce, $settings->mode());
        $this->assertSame(['notify' => 50, 'step_up' => 70, 'revoke_sessions' => null, 'suspend' => null], $settings->thresholds());
        $this->assertFalse($settings->isCategoryMonitored('payments'));
        $this->assertSame(30, $settings->retentionDays());
        $this->assertDatabaseHas('security_events', ['user_id' => $admin->id, 'type' => 'admin.security_changed']);
    }

    public function test_switching_aegis_off_is_recorded_before_it_stops(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.security.settings.update'), $this->settingsPayload(['mode' => 'off']));

        $this->assertSame(SecurityMode::Off, app(SecuritySettings::class)->mode());
        $this->assertSame(['mode' => 'off'], SecurityEvent::query()->where('type', 'admin.security_changed')->sole()->context);
    }

    public function test_a_stronger_response_cannot_have_a_lower_threshold(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.security.settings.update'), $this->settingsPayload([
            'thresholds' => ['notify' => 80, 'step_up' => 70, 'revoke_sessions' => 90, 'suspend' => null],
        ]))->assertSessionHasErrors('thresholds.step_up');
    }

    public function test_unknown_categories_are_rejected(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.security.settings.update'), $this->settingsPayload([
            'disabled_categories' => ['nonsense'],
        ]))->assertSessionHasErrors('disabled_categories.0');
    }

    public function test_the_guide_reports_activation_status_and_scope(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('admin.security.guide'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SecurityGuide')
            ->where('checklist.0.ok', true)
            ->where('checklist.2.ok', false)
            ->where('categories.0.key', 'authentication')
            ->where('categories.0.monitored', true)
            ->where('signals.0.key', 'sequence_anomaly'));
    }

    public function test_admin_setting_changes_elsewhere_are_audited(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.api-docs.update'), ['visibility' => 'public'])->assertSessionHas('status');
        $this->actingAs($admin)->put(route('admin.api-docs.update'), ['visibility' => 'nonsense'])->assertSessionHasErrors('visibility');

        $this->assertSame(1, SecurityEvent::query()->where('type', 'admin.settings_changed')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        return [
            'mode' => 'monitor',
            'thresholds' => SecuritySettings::DEFAULT_THRESHOLDS,
            'incident_threshold' => 60,
            'disabled_categories' => [],
            'retention_days' => 90,
            'ai_enabled' => false,
            'ai_threshold' => 70,
            ...$overrides,
        ];
    }

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole('super_admin');
    }
}
