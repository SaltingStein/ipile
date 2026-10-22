<?php

namespace Tests\Feature\Services\Security;

use App\Events\Security\SecurityIncidentOpened;
use App\Events\Security\SecurityRiskAssessed;
use App\Http\Middleware\EnforceSecurityStepUp;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityRiskState;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Security\IncidentManager;
use App\Services\Security\Security;
use App\Services\Security\SecurityEventDefinition;
use App\Services\Security\SecuritySettings;
use App\Services\Security\Signals\SignalContext;
use App\Services\Security\Signals\SignalResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SecurityMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A test event whose score equals 100 × the `risk` context value, so each test controls the score.
        Security::define(new SecurityEventDefinition('test.action', 'testing', 'Test action', 'Used by tests.', sensitivity: 1.0));
        Security::define(new SecurityEventDefinition('test.failed_attempt', 'testing', 'Test failure', 'Used by tests.',
            sensitivity: 1.0, isFailure: true, actorIsVerified: false));
        Security::signal(ContextRiskSignal::class);
    }

    public function test_nothing_is_recorded_when_aegis_is_off(): void
    {
        Setting::store(SecuritySettings::MODE, 'off');

        $this->assertNull(Security::recordFor(User::factory()->create(), 'test.action', ['risk' => 1.0]));
        $this->assertDatabaseCount('security_events', 0);
    }

    public function test_an_unknown_event_type_fails_loudly_even_when_off(): void
    {
        Setting::store(SecuritySettings::MODE, 'off');

        $this->expectException(InvalidArgumentException::class);

        Security::record('test.typo');
    }

    public function test_events_in_a_disabled_category_are_ignored(): void
    {
        Setting::store(SecuritySettings::DISABLED_CATEGORIES, json_encode(['testing']));

        $this->assertNull(Security::recordFor(User::factory()->create(), 'test.action'));
        $this->assertDatabaseCount('security_events', 0);
    }

    public function test_monitor_mode_scores_and_opens_an_incident_without_acting(): void
    {
        Notification::fake();
        Event::fake([SecurityIncidentOpened::class, SecurityRiskAssessed::class]);
        $user = User::factory()->create();
        $user->createToken('phone');

        $event = Security::recordFor($user, 'test.action', ['risk' => 0.95, 'note' => 'kept']);

        $this->assertSame(95, $event->risk_score);
        $this->assertSame('revoke_sessions', $event->recommended_action);
        $this->assertNull($event->action_taken);
        $this->assertSame(['risk' => 0.95, 'note' => 'kept'], $event->context);
        $this->assertContains('context_risk', array_column($event->signals, 'key'));
        $this->assertNotNull($event->security_incident_id);
        $this->assertSame(1, $user->tokens()->count());
        Notification::assertNothingSent();
        Event::assertDispatched(SecurityIncidentOpened::class);
        Event::assertDispatched(SecurityRiskAssessed::class, fn (SecurityRiskAssessed $assessed) => $assessed->event->is($event) && $assessed->decisionScore === 95);
    }

    public function test_low_risk_events_are_recorded_without_an_incident(): void
    {
        $event = Security::recordFor(User::factory()->create(), 'test.action', ['risk' => 0.1]);

        $this->assertSame(10, $event->risk_score);
        $this->assertNull($event->security_incident_id);
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_enforce_mode_signs_the_account_out_everywhere_and_alerts_once(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        $user = User::factory()->create();
        $user->createToken('phone');
        TrustedDevice::factory()->for($user)->create();

        $first = Security::recordFor($user, 'test.action', ['risk' => 0.95]);
        $second = Security::recordFor($user, 'test.action', ['risk' => 0.95]);

        $this->assertSame('revoke_sessions', $first->fresh()->action_taken);
        $this->assertNull($second->fresh()->action_taken, 'The same response is not repeated within an episode.');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->trustedDevices()->count());
        $this->assertSame('revoke_sessions', SecurityIncident::query()->sole()->action_taken);
        $this->assertSame(2, SecurityIncident::query()->sole()->event_count);
        Notification::assertSentToTimes($user, EventNotification::class, 1);
    }

    public function test_suspension_only_happens_when_an_administrator_sets_a_threshold(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        Setting::store(SecuritySettings::THRESHOLDS, json_encode(['notify' => 60, 'step_up' => 75, 'revoke_sessions' => 90, 'suspend' => 95]));
        $user = User::factory()->create();

        Security::recordFor($user, 'test.action', ['risk' => 1.0]);

        $this->assertTrue($user->fresh()->isSuspended());
    }

    public function test_failures_anyone_can_cause_never_go_beyond_alerting_the_owner(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        $user = User::factory()->create();
        $user->createToken('phone');

        $event = Security::recordFor($user, 'test.failed_attempt', ['risk' => 1.0]);

        $this->assertSame('notify', $event->fresh()->action_taken);
        $this->assertSame(1, $user->tokens()->count());
        Notification::assertSentTo($user, EventNotification::class);
    }

    public function test_step_up_asks_the_current_browser_to_confirm_its_password(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        Route::middleware(['web', 'auth'])->post('/_test/risky', fn () => response()->noContent()
            ->setStatusCode(Security::record('test.action', ['risk' => 0.8])?->action_taken === 'step_up' ? 204 : 500));
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/_test/risky');

        $response->assertNoContent();
        $response->assertSessionHas(EnforceSecurityStepUp::SESSION_KEY);
    }

    public function test_the_step_up_middleware_redirects_until_the_password_is_confirmed(): void
    {
        $user = User::factory()->create();
        $requestedAt = now()->getTimestamp();

        $this->actingAs($user)
            ->withSession([EnforceSecurityStepUp::SESSION_KEY => $requestedAt])
            ->get(route('dashboard'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($user)
            ->withSession([EnforceSecurityStepUp::SESSION_KEY => $requestedAt, 'auth.password_confirmed_at' => $requestedAt + 1])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionMissing(EnforceSecurityStepUp::SESSION_KEY);
    }

    public function test_repeated_moderate_events_escalate_the_account_posture(): void
    {
        $user = User::factory()->create();

        $first = Security::recordFor($user, 'test.action', ['risk' => 0.55]);
        $second = Security::recordFor($user, 'test.action', ['risk' => 0.55]);
        $third = Security::recordFor($user, 'test.action', ['risk' => 0.55]);

        $this->assertLessThan($second->posture_score, $first->posture_score);
        $this->assertLessThan($third->posture_score, $second->posture_score);
        $this->assertSame('elevated', SecurityRiskState::query()->where('user_id', $user->id)->value('level'));
        $this->assertSame($third->posture_score, SecurityRiskState::query()->where('user_id', $user->id)->value('score'));
    }

    public function test_risky_signed_out_activity_is_grouped_by_ip_address(): void
    {
        Security::recordFor(null, 'test.failed_attempt', ['risk' => 0.9]);
        Security::recordFor(null, 'test.failed_attempt', ['risk' => 0.9]);

        $incident = SecurityIncident::query()->sole();
        $this->assertNull($incident->user_id);
        $this->assertSame('127.0.0.1', $incident->ip_address);
        $this->assertSame(2, $incident->event_count);
    }

    public function test_recent_failures_from_the_same_address_raise_the_risk(): void
    {
        Security::define(new SecurityEventDefinition('test.quiet', 'testing', 'Quiet', 'Used by tests.', sensitivity: 0.5));
        $user = User::factory()->create();
        SecurityEvent::factory()->count(4)->ofType('auth.login_failed', 'authentication')->create(['user_id' => null, 'ip_address' => '127.0.0.1']);

        $event = Security::recordFor($user, 'test.quiet');

        $signal = collect($event->signals)->firstWhere('key', 'recent_failures');
        $this->assertSame(0.5, $signal['score']);
        $this->assertGreaterThan(0, $event->risk_score);
    }

    public function test_a_browser_never_seen_on_the_account_is_flagged(): void
    {
        Security::define(new SecurityEventDefinition('test.quiet', 'testing', 'Quiet', 'Used by tests.', sensitivity: 0.5));
        $user = User::factory()->create();
        SecurityEvent::factory()->count(3)->for($user)->create(['occurred_at' => now()->subDay(), 'device_hash' => hash('sha256', 'Old browser')]);

        $event = $this->withHeader('User-Agent', 'New browser')->recordThroughRequest($user, 'test.quiet');

        $this->assertContains('new_device', array_column($event->signals, 'key'));
    }

    public function test_a_broken_custom_signal_does_not_stop_scoring(): void
    {
        Security::signal(BrokenSignal::class);

        $event = Security::recordFor(User::factory()->create(), 'test.action', ['risk' => 0.5]);

        $this->assertSame(50, $event->risk_score);
    }

    public function test_monitoring_fails_open(): void
    {
        $this->mock(IncidentManager::class)->shouldReceive('capture')->andThrow(new RuntimeException('Database bug'));

        $this->assertNull(Security::recordFor(User::factory()->create(), 'test.action', ['risk' => 0.95]));
    }

    public function test_a_broken_listener_does_not_stop_monitoring(): void
    {
        Event::listen(SecurityRiskAssessed::class, fn () => throw new RuntimeException('Listener bug'));

        $event = Security::recordFor(User::factory()->create(), 'test.action', ['risk' => 0.95]);

        $this->assertNotNull($event);
        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_application_events_can_be_mapped_with_listen(): void
    {
        $user = User::factory()->create();
        Security::listen(OrderRefunded::class, 'test.action', fn (OrderRefunded $event) => $event->customer, fn (OrderRefunded $event) => ['risk' => 0.2]);

        event(new OrderRefunded($user));

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'test.action', 'risk_score' => 20]);
    }

    public function test_the_track_middleware_records_successful_requests_only(): void
    {
        Route::middleware(['web', 'auth', 'security.track:test.action'])->post('/_test/ok', fn () => 'ok');
        Route::middleware(['web', 'auth', 'security.track:test.action'])->post('/_test/invalid', fn () => request()->validate(['name' => 'required']));
        $user = User::factory()->create();

        $this->actingAs($user)->post('/_test/ok')->assertOk();
        $this->actingAs($user)->post('/_test/invalid')->assertSessionHasErrors('name');

        $this->assertSame(1, SecurityEvent::query()->where('type', 'test.action')->count());
    }

    /**
     * Record from inside a real request, so the request's headers are used.
     */
    private function recordThroughRequest(User $user, string $type): SecurityEvent
    {
        Route::middleware('web')->post('/_test/record', fn () => ['id' => Security::recordFor($user, $type)?->id]);

        return SecurityEvent::query()->findOrFail($this->post('/_test/record')->json('id'));
    }
}

class BrokenSignal extends ContextRiskSignal
{
    public function evaluate(SignalContext $context): SignalResult
    {
        throw new RuntimeException('Signal bug');
    }
}

class OrderRefunded
{
    public function __construct(public User $customer) {}
}
