<?php

namespace Tests\Feature\Services\Security;

use App\Events\Security\SecurityAccountRestored;
use App\Events\Security\SecurityActionTaken;
use App\Events\Security\SecurityEventRecorded;
use App\Events\Security\SecurityIncidentResolved;
use App\Events\Security\SecurityRiskAssessed;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\IncidentManager;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventDefinition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Covers the security lifecycle events and their versioned export payload (schema 1).
 */
class SecurityLifecycleEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Scores 100 × the `risk` context value; 60 or more opens an incident.
        Security::define(new SecurityEventDefinition('test.action', 'testing', 'Test action', 'Used by tests.', sensitivity: 1.0));
        Security::signal(ContextRiskSignal::class);
    }

    public function test_every_recorded_event_is_announced(): void
    {
        $user = User::factory()->create();
        Event::fake([SecurityEventRecorded::class]);

        $event = Security::recordFor($user, 'test.action', ['risk' => 0.1]);

        Event::assertDispatched(SecurityEventRecorded::class, fn (SecurityEventRecorded $recorded) => $recorded->event->is($event));
    }

    public function test_events_are_announced_only_after_the_surrounding_transaction_commits(): void
    {
        $user = User::factory()->create();
        $announced = 0;
        Event::listen(SecurityEventRecorded::class, function () use (&$announced): void {
            $announced++;
        });

        DB::transaction(function () use ($user, &$announced): void {
            Security::recordFor($user, 'test.action');

            $this->assertSame(0, $announced);
        });

        $this->assertSame(1, $announced);
    }

    public function test_resolving_an_incident_is_announced(): void
    {
        $incident = Security::recordFor(User::factory()->create(), 'test.action', ['risk' => 0.9])->incident;
        Event::fake([SecurityIncidentResolved::class]);

        app(IncidentManager::class)->resolve($incident, User::factory()->create(), 'benign');

        Event::assertDispatched(SecurityIncidentResolved::class, fn (SecurityIncidentResolved $resolved) => $resolved->incident->is($incident)
            && $resolved->incident->status === SecurityIncident::STATUS_RESOLVED);
    }

    public function test_restoring_an_account_from_its_incident_is_announced(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->suspended()->create();
        $incident = Security::recordFor($user, 'test.action', ['risk' => 0.9])->incident;
        $admin = User::factory()->create()->givePermissionTo(['view_security', 'suspend_users']);
        Event::fake([SecurityAccountRestored::class]);

        $this->actingAs($admin)->post(route('admin.security.incidents.restore', $incident))->assertSessionHasNoErrors();

        Event::assertDispatched(SecurityAccountRestored::class, fn (SecurityAccountRestored $restored) => $restored->user->is($user)
            && $restored->restoredBy->is($admin)
            && $restored->incident->is($incident)
            && $restored->via === SecurityAccountRestored::VIA_INCIDENT);
    }

    public function test_restoring_an_account_from_the_console_is_announced(): void
    {
        $user = User::factory()->suspended()->create(['email' => 'ada@example.com']);
        Event::fake([SecurityAccountRestored::class]);

        $this->artisan('ipile:restore-user', ['email' => 'ada@example.com'])->assertSuccessful();

        Event::assertDispatched(SecurityAccountRestored::class, fn (SecurityAccountRestored $restored) => $restored->user->is($user)
            && $restored->restoredBy === null
            && $restored->via === SecurityAccountRestored::VIA_CONSOLE);
    }

    public function test_an_account_that_is_not_suspended_is_not_restored_or_announced(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Event::fake([SecurityAccountRestored::class]);

        $this->artisan('ipile:restore-user', ['email' => 'ada@example.com'])->assertSuccessful();

        Event::assertNotDispatched(SecurityAccountRestored::class);
    }

    public function test_the_export_payload_keys_are_pinned(): void
    {
        $user = User::factory()->create();
        Event::fake([SecurityRiskAssessed::class]);

        Security::recordFor($user, 'test.action', ['risk' => 0.9]);

        $payload = $this->exportOf(SecurityRiskAssessed::class);
        $this->assertSame(['schema', 'type', 'occurred_at', 'user_reference', 'decision_score', 'event', 'incident'], array_keys($payload));
        $this->assertSame(1, $payload['schema']);
        $this->assertSame('security.risk_assessed', $payload['type']);
        $this->assertSame(['id', 'type', 'category', 'risk_score', 'posture_score', 'recommended_action', 'action_taken', 'signals', 'incident_id', 'occurred_at'], array_keys($payload['event']));
        $this->assertSame(['key', 'score'], array_keys($payload['event']['signals'][0]));
        $this->assertSame(['id', 'status', 'risk_score', 'risk_level', 'recommended_action', 'action_taken', 'event_count', 'resolution', 'first_event_at', 'last_event_at', 'resolved_at'], array_keys($payload['incident']));
        $this->assertSame(
            ['schema', 'type', 'occurred_at', 'user_reference', 'action', 'requested_action', 'source', 'event_id', 'event_type', 'incident_id'],
            array_keys((new SecurityActionTaken($user, SecurityAction::Notify, SecurityAction::StepUp, null, null))->toExport()),
        );
        $this->assertSame(
            ['schema', 'type', 'occurred_at', 'user_reference', 'via', 'incident_id'],
            array_keys((new SecurityAccountRestored($user, null, null, SecurityAccountRestored::VIA_CONSOLE))->toExport()),
        );
    }

    public function test_exports_carry_no_personal_data(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        Event::fake([SecurityRiskAssessed::class]);

        Security::recordFor($user, 'test.action', ['risk' => 0.9, 'note' => 'private-note']);

        // The test request's IP address is 127.0.0.1 and its user agent "Symfony"; both are stored on the event.
        $export = json_encode($this->exportOf(SecurityRiskAssessed::class));
        foreach (['Ada Lovelace', 'ada@example.com', '127.0.0.1', 'Symfony', 'private-note'] as $personal) {
            $this->assertStringNotContainsString($personal, $export);
        }
        $this->assertNull($this->exportOf(SecurityRiskAssessed::class)['user_reference']);
    }

    public function test_user_references_are_a_stable_keyed_hash_when_turned_on(): void
    {
        $ada = User::factory()->create();
        $grace = User::factory()->create();

        Security::exportUserReferences('first-key');
        $reference = (new SecurityAccountRestored($ada, null, null, 'console'))->toExport()['user_reference'];
        $again = (new SecurityAccountRestored($ada, null, null, 'console'))->toExport()['user_reference'];
        $otherPerson = (new SecurityAccountRestored($grace, null, null, 'console'))->toExport()['user_reference'];
        Security::exportUserReferences('second-key');
        $otherKey = (new SecurityAccountRestored($ada, null, null, 'console'))->toExport()['user_reference'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $reference);
        $this->assertSame($reference, $again);
        $this->assertNotSame($reference, $otherPerson);
        $this->assertNotSame($reference, $otherKey);
    }

    /**
     * @param  class-string  $eventClass
     * @return array<string, mixed>
     */
    private function exportOf(string $eventClass): array
    {
        $payload = null;

        Event::assertDispatched($eventClass, function (object $event) use (&$payload): bool {
            $payload = $event->toExport();

            return true;
        });

        return $payload;
    }
}
