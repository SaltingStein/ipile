<?php

namespace Tests\Feature\Services\Security;

use App\Models\User;
use App\Services\Security\Security;
use App\Services\Security\SecurityEventDefinition;
use App\Services\Security\Signals\SecuritySignals;
use App\Services\Security\Signals\ThresholdSignal;
use App\Services\Security\Signals\TimeWindowSignal;
use App\Services\Security\Signals\VelocitySignal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Covers the opt-in signal building blocks: velocity, value threshold and expected hours.
 */
class ConfigurableSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Security::define(new SecurityEventDefinition('test.payout', 'testing', 'Payout', 'Used by tests.', sensitivity: 1.0));
        Security::define(new SecurityEventDefinition('test.other', 'testing', 'Other', 'Used by tests.', sensitivity: 1.0));
    }

    public function test_no_building_block_is_registered_by_default(): void
    {
        $keys = array_map(fn ($signal) => $signal->key(), app(SecuritySignals::class)->all());

        $this->assertSame(['sequence_anomaly', 'new_device', 'new_network', 'burst', 'recent_failures'], $keys);
    }

    public function test_velocity_scores_once_the_account_exceeds_the_rate(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Security::signal(VelocitySignal::for('test.payout', max: 2, perMinutes: 60));

        $first = Security::recordFor($user, 'test.payout');
        Security::recordFor($user, 'test.payout');
        $third = Security::recordFor($user, 'test.payout');
        $fourth = Security::recordFor($user, 'test.payout');

        $this->assertNull($this->signalScore($first, 'velocity:test.payout'));
        $this->assertSame(0.5, $this->signalScore($third, 'velocity:test.payout'));
        $this->assertSame(1.0, $this->signalScore($fourth, 'velocity:test.payout'));
    }

    public function test_velocity_ignores_other_event_types_and_events_outside_the_window(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Security::signal(VelocitySignal::for('test.payout', max: 1, perMinutes: 60));
        Security::recordFor($user, 'test.payout');
        $this->travel(61)->minutes();

        $later = Security::recordFor($user, 'test.payout');
        $other = Security::recordFor($user, 'test.other');

        $this->assertNull($this->signalScore($later, 'velocity:test.payout'));
        $this->assertNull($this->signalScore($other, 'velocity:test.payout'));
    }

    public function test_threshold_scores_values_above_the_limit(): void
    {
        $user = User::factory()->create();
        Security::signal(ThresholdSignal::on('test.payout', 'amount_kobo', above: 50_000));

        $large = Security::recordFor($user, 'test.payout', ['amount_kobo' => 75_000]);
        $double = Security::recordFor($user, 'test.payout', ['amount_kobo' => 100_000]);
        $small = Security::recordFor($user, 'test.payout', ['amount_kobo' => 30_000]);
        $missing = Security::recordFor($user, 'test.payout');

        $this->assertSame(0.8, $this->signalScore($large, 'threshold:test.payout:amount_kobo'));
        $this->assertSame(1.0, $this->signalScore($double, 'threshold:test.payout:amount_kobo'));
        $this->assertNull($this->signalScore($small, 'threshold:test.payout:amount_kobo'));
        $this->assertNull($this->signalScore($missing, 'threshold:test.payout:amount_kobo'));
    }

    public function test_threshold_compares_with_the_accounts_usual_values_once_there_is_enough_history(): void
    {
        $user = User::factory()->create();
        Security::signal(ThresholdSignal::on('test.payout', 'amount_kobo', timesUsual: 5));

        foreach ([1_000, 1_200, 900, 1_100] as $amount) {
            Security::recordFor($user, 'test.payout', ['amount_kobo' => $amount]);
        }
        $tooEarly = Security::recordFor($user, 'test.payout', ['amount_kobo' => 20_000]);
        $usual = Security::recordFor($user, 'test.payout', ['amount_kobo' => 1_050]);
        $unusual = Security::recordFor($user, 'test.payout', ['amount_kobo' => 20_000]);

        $this->assertNull($this->signalScore($tooEarly, 'threshold:test.payout:amount_kobo'));
        $this->assertNull($this->signalScore($usual, 'threshold:test.payout:amount_kobo'));
        $this->assertSame(1.0, $this->signalScore($unusual, 'threshold:test.payout:amount_kobo'));
    }

    public function test_time_window_scores_activity_outside_the_expected_hours(): void
    {
        $user = User::factory()->create();
        Security::signal(TimeWindowSignal::for('test.payout', outside: '06:00-22:00', timezone: 'Africa/Lagos'));

        $this->travelTo(CarbonImmutable::parse('2026-10-21 22:30:00', 'UTC'));
        $night = Security::recordFor($user, 'test.payout');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 11:00:00', 'UTC'));
        $day = Security::recordFor($user, 'test.payout');

        $this->assertSame(1.0, $this->signalScore($night, 'time_window:test.payout'));
        $this->assertNull($this->signalScore($day, 'time_window:test.payout'));
    }

    public function test_a_time_window_may_cross_midnight(): void
    {
        $user = User::factory()->create();
        Security::signal(TimeWindowSignal::for('test.payout', outside: '22:00-06:00', timezone: 'UTC'));

        $this->travelTo(CarbonImmutable::parse('2026-10-21 02:00:00', 'UTC'));
        $night = Security::recordFor($user, 'test.payout');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 14:00:00', 'UTC'));
        $afternoon = Security::recordFor($user, 'test.payout');

        $this->assertNull($this->signalScore($night, 'time_window:test.payout'));
        $this->assertSame(1.0, $this->signalScore($afternoon, 'time_window:test.payout'));
    }

    public function test_building_blocks_add_up_with_the_other_signals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 03:00:00', 'UTC'));
        $user = User::factory()->create();
        Security::signal(ThresholdSignal::on('test.payout', 'amount_kobo', above: 50_000));
        $thresholdOnly = Security::recordFor($user, 'test.payout', ['amount_kobo' => 100_000]);
        Security::signal(TimeWindowSignal::for('test.payout', outside: '06:00-22:00', timezone: 'UTC'));

        $both = Security::recordFor($user, 'test.payout', ['amount_kobo' => 100_000]);

        $this->assertGreaterThan($thresholdOnly->risk_score, $both->risk_score);
    }

    public function test_invalid_configuration_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TimeWindowSignal::for('test.payout', outside: '6am-10pm');
    }

    private function signalScore(mixed $event, string $key): ?float
    {
        foreach ($event->signals ?? [] as $signal) {
            if ($signal['key'] === $key) {
                return (float) $signal['score'];
            }
        }

        return null;
    }
}
