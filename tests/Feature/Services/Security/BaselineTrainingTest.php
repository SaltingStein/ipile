<?php

namespace Tests\Feature\Services\Security;

use App\Models\SecurityBaseline;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\Markov\SequenceAnomalyDetector;
use App\Services\Security\Security;
use App\Services\Security\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaselineTrainingTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_learns_each_accounts_habits_and_scoring_uses_them(): void
    {
        $user = User::factory()->create();
        $this->habitualSessions($user, 12);

        $this->artisan('security:train')->assertSuccessful();

        $personal = SecurityBaseline::query()->where('scope', 'user:'.$user->id)->sole();
        $this->assertSame(12, $personal->transitions[SequenceAnomalyDetector::START]['auth.login']);
        $this->assertSame(12, $personal->transitions['auth.login']['payment.initiated']);
        $this->assertTrue(SecurityBaseline::query()->where('scope', 'global')->exists());
        $this->assertTrue(SecurityBaseline::query()->where('scope', 'role:none')->exists());
        $this->assertNotNull(app(SecuritySettings::class)->lastTrainedAt());

        Security::recordFor($user, 'auth.login');
        $unusual = Security::recordFor($user, 'account.two_factor_disabled');

        $anomaly = collect($unusual->signals)->firstWhere('key', 'sequence_anomaly');
        $this->assertNotNull($anomaly);
        $this->assertGreaterThan(0.8, $anomaly['score']);
    }

    public function test_habitual_behaviour_is_not_flagged_after_training(): void
    {
        $user = User::factory()->create();
        $this->habitualSessions($user, 12);
        $this->artisan('security:train');

        Security::recordFor($user, 'auth.login');
        $usual = Security::recordFor($user, 'payment.initiated');

        $this->assertNotContains('sequence_anomaly', array_column($usual->signals, 'key'));
    }

    public function test_accounts_with_little_history_get_no_personal_baseline(): void
    {
        $user = User::factory()->create();
        $this->habitualSessions($user, 3);

        $this->artisan('security:train');

        $this->assertFalse(SecurityBaseline::query()->where('scope', 'user:'.$user->id)->exists());
    }

    public function test_events_from_confirmed_attacks_are_not_learned(): void
    {
        $user = User::factory()->create();
        $incident = SecurityIncident::factory()->for($user)->resolved('confirmed')->create();
        SecurityEvent::factory()->for($user)->ofType('account.two_factor_disabled', 'account')
            ->create(['security_incident_id' => $incident->id, 'occurred_at' => now()->subHour()]);

        $this->artisan('security:train');

        $global = SecurityBaseline::query()->where('scope', 'global')->first();
        $this->assertArrayNotHasKey('account.two_factor_disabled', $global?->transitions[SequenceAnomalyDetector::START] ?? []);
    }

    public function test_pruning_deletes_data_older_than_the_retention_period(): void
    {
        $old = SecurityEvent::factory()->create(['occurred_at' => now()->subDays(120)]);
        $recent = SecurityEvent::factory()->create(['occurred_at' => now()->subDays(5)]);
        $oldResolved = SecurityIncident::factory()->resolved()->create(['last_event_at' => now()->subDays(120)]);
        $oldButOpen = SecurityIncident::factory()->create(['last_event_at' => now()->subDays(120)]);

        $this->artisan('security:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelMissing($oldResolved);
        $this->assertModelExists($oldButOpen);
    }

    /**
     * Sessions of "sign in, then pay", a day apart, ending a day ago.
     */
    private function habitualSessions(User $user, int $sessions): void
    {
        for ($session = $sessions; $session >= 1; $session--) {
            $start = now()->subDays($session)->subHours(2);

            SecurityEvent::factory()->for($user)->ofType('auth.login', 'authentication')->create(['occurred_at' => $start]);
            SecurityEvent::factory()->for($user)->ofType('payment.initiated', 'payments')->create(['occurred_at' => $start->copy()->addMinutes(2)]);
        }
    }
}
