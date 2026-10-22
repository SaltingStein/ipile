<?php

namespace Tests\Unit\Services\Security;

use App\Services\Security\Markov\RiskPostureChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RiskPostureChainTest extends TestCase
{
    public function test_calm_events_keep_a_normal_account_normal(): void
    {
        $chain = new RiskPostureChain;

        $belief = $chain->observe($chain->initial(), 10);

        $this->assertSame(0, $chain->score($belief));
    }

    public function test_repeated_risky_events_escalate_beyond_a_single_one(): void
    {
        $chain = new RiskPostureChain;

        $once = $chain->observe($chain->initial(), 80);
        $twice = $chain->observe($once, 80);

        $this->assertGreaterThan(40, $chain->score($once));
        $this->assertGreaterThan($chain->score($once), $chain->score($twice));
        $this->assertSame('high', RiskPostureChain::levelFor($chain->score($twice)));
    }

    public function test_quiet_time_decays_the_posture_back_to_normal(): void
    {
        $chain = new RiskPostureChain;
        $risky = $chain->observe($chain->observe($chain->initial(), 90), 90);

        $afterAnHour = $chain->decay($risky, 60);
        $afterADay = $chain->decay($risky, 60 * 24);

        $this->assertLessThan($chain->score($risky), $chain->score($afterAnHour));
        $this->assertLessThan(20, $chain->score($afterADay));
    }

    public function test_the_belief_stays_a_probability_distribution(): void
    {
        $chain = new RiskPostureChain;
        $belief = $chain->initial();

        foreach ([80, 20, 55, 95, 35] as $score) {
            $belief = $chain->observe($chain->decay($belief, 45), $score);
        }

        $this->assertEqualsWithDelta(1.0, array_sum($belief), 0.0001);
    }

    public function test_a_malformed_stored_belief_starts_over(): void
    {
        $chain = new RiskPostureChain;

        $this->assertSame($chain->initial(), $chain->normalise(['oops']));
        $this->assertSame($chain->initial(), $chain->normalise(null));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function levels(): array
    {
        return [
            'normal' => [29, 'normal'],
            'elevated' => [30, 'elevated'],
            'high' => [60, 'high'],
            'critical' => [85, 'critical'],
        ];
    }

    #[DataProvider('levels')]
    public function test_scores_map_to_levels(int $score, string $level): void
    {
        $this->assertSame($level, RiskPostureChain::levelFor($score));
    }
}
