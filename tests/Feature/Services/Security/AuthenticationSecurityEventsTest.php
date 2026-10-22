<?php

namespace Tests\Feature\Services\Security;

use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\SecuritySettings;
use App\Services\Teams\TeamManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The built-in hooks: real sign-in, password, API and team flows feed Aegis.
 */
class AuthenticationSecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_web_sign_in_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'auth.login']);
    }

    public function test_a_wrong_password_is_recorded_against_the_targeted_account(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'auth.login_failed']);
        $this->assertDatabaseHas('security_events', ['user_id' => null, 'type' => 'auth.login_failed', 'ip_address' => '127.0.0.1']);
    }

    public function test_repeated_failures_lead_to_a_lockout_event_with_rising_risk(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $lockout = SecurityEvent::query()->where('type', 'auth.lockout')->sole();
        $this->assertSame($user->id, $lockout->user_id);
        $this->assertContains('recent_failures', array_column($lockout->signals, 'key'));
        $this->assertGreaterThan(
            SecurityEvent::query()->where('type', 'auth.login_failed')->orderBy('id')->value('risk_score'),
            $lockout->risk_score,
        );
    }

    public function test_changing_the_password_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'account.password_changed']);
    }

    public function test_issuing_an_api_token_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel 9'])->assertOk();

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'api.token_created', 'category' => 'api']);
    }

    public function test_team_changes_are_recorded_with_their_context(): void
    {
        $owner = User::factory()->create();

        $team = app(TeamManager::class)->create($owner, ['name' => 'Acme']);

        $event = SecurityEvent::query()->where('type', 'team.created')->sole();
        $this->assertSame($owner->id, $event->user_id);
        $this->assertSame(['team_id' => $team->id], $event->context);
    }

    public function test_nothing_is_recorded_from_real_flows_when_aegis_is_off(): void
    {
        Setting::store(SecuritySettings::MODE, 'off');
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseCount('security_events', 0);
    }
}
