<?php

namespace Tests\Unit\Services\Security;

use App\Services\Security\Markov\SequenceAnomalyDetector;
use App\Services\Security\Markov\TransitionMatrix;
use PHPUnit\Framework\TestCase;

class SequenceAnomalyDetectorTest extends TestCase
{
    private const VOCABULARY = ['auth.login', 'payment.initiated', 'account.two_factor_disabled', 'auth.logout'];

    public function test_nothing_is_anomalous_without_history(): void
    {
        $anomaly = (new SequenceAnomalyDetector)->anomaly('auth.login', 'account.two_factor_disabled', [new TransitionMatrix], self::VOCABULARY);

        $this->assertSame(0.0, $anomaly);
    }

    public function test_a_habitual_transition_is_expected_and_an_unseen_one_is_anomalous(): void
    {
        $global = new TransitionMatrix(['auth.login' => ['payment.initiated' => 200, 'auth.logout' => 20]]);
        $detector = new SequenceAnomalyDetector;

        $habitual = $detector->anomaly('auth.login', 'payment.initiated', [$global], self::VOCABULARY);
        $unseen = $detector->anomaly('auth.login', 'account.two_factor_disabled', [$global], self::VOCABULARY);

        $this->assertSame(0.0, $habitual);
        $this->assertGreaterThan(0.9, $unseen);
    }

    public function test_an_account_with_its_own_history_is_judged_against_it(): void
    {
        $global = new TransitionMatrix(['auth.login' => ['payment.initiated' => 500]]);
        $account = new TransitionMatrix(['auth.login' => ['auth.logout' => 60]]);
        $detector = new SequenceAnomalyDetector;

        $forEveryone = $detector->anomaly('auth.login', 'auth.logout', [$global], self::VOCABULARY);
        $forThisAccount = $detector->anomaly('auth.login', 'auth.logout', [$global, $account], self::VOCABULARY);

        $this->assertGreaterThan(0.9, $forEveryone);
        $this->assertLessThan(0.1, $forThisAccount);
    }

    public function test_a_scope_with_little_data_mostly_defers_to_its_parent(): void
    {
        $global = new TransitionMatrix(['auth.login' => ['payment.initiated' => 500]]);
        $newAccount = new TransitionMatrix(['auth.login' => ['auth.logout' => 1]]);

        $distribution = (new SequenceAnomalyDetector)->distribution('auth.login', [$global, $newAccount], self::VOCABULARY);

        $this->assertGreaterThan($distribution['auth.logout'], $distribution['payment.initiated']);
        $this->assertEqualsWithDelta(1.0, array_sum($distribution), 0.0001);
    }

    public function test_an_event_outside_the_vocabulary_is_still_scored(): void
    {
        $global = new TransitionMatrix(['auth.login' => ['payment.initiated' => 200]]);

        $anomaly = (new SequenceAnomalyDetector)->anomaly('auth.login', 'orders.refunded', [$global], self::VOCABULARY);

        $this->assertGreaterThan(0.9, $anomaly);
    }
}
