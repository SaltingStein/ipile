<?php

namespace Tests\Feature\Services\Security;

use App\Events\Security\SecurityActionTaken;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventDefinition;
use App\Services\Security\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers builder response handlers ({@see Security::onAction()}), the SecurityActionTaken event and per-event
 * minimum actions.
 */
class SecurityResponseHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Scores 100 × the `risk` context value (default thresholds: alert 60, password re-check 75, sign out 90).
        Security::define(new SecurityEventDefinition('test.action', 'testing', 'Test action', 'Used by tests.', sensitivity: 1.0));
        Security::signal(ContextRiskSignal::class);
    }

    public function test_handlers_run_in_order_for_the_response_taken(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        $user = User::factory()->create();
        $calls = [];
        Security::onAction(SecurityAction::RevokeSessions, function (SecurityActionTaken $taken) use (&$calls): void {
            $calls[] = ['first', $taken->user->id, $taken->event?->type, $taken->incident !== null, $taken->source];
        });
        Security::onAction(SecurityAction::RevokeSessions, function () use (&$calls): void {
            $calls[] = 'second';
        });
        Security::onAction(SecurityAction::Suspend, function () use (&$calls): void {
            $calls[] = 'suspend handler';
        });

        Security::recordFor($user, 'test.action', ['risk' => 0.95]);

        $this->assertSame([['first', $user->id, 'test.action', true, 'aegis'], 'second'], $calls);
    }

    public function test_class_handlers_are_resolved_from_the_container(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        $user = User::factory()->create();
        Security::onAction(SecurityAction::RevokeSessions, FreezeWallet::class);

        Security::recordFor($user, 'test.action', ['risk' => 0.95]);

        $this->assertSame([$user->id], FreezeWallet::$frozen);
    }

    public function test_a_failing_handler_is_reported_without_stopping_other_handlers_or_the_response(): void
    {
        Exceptions::fake();
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        $user = User::factory()->create();
        $user->createToken('phone');
        $laterHandlerRan = false;
        Security::onAction(SecurityAction::RevokeSessions, fn () => throw new RuntimeException('Wallet service down'));
        Security::onAction(SecurityAction::RevokeSessions, function () use (&$laterHandlerRan): void {
            $laterHandlerRan = true;
        });

        $event = Security::recordFor($user, 'test.action', ['risk' => 0.95]);

        $this->assertTrue($laterHandlerRan);
        $this->assertSame('revoke_sessions', $event->action_taken);
        $this->assertSame(0, $user->tokens()->count());
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Wallet service down');
    }

    public function test_every_response_is_announced(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        $user = User::factory()->create();
        Event::fake([SecurityActionTaken::class]);

        Security::recordFor($user, 'test.action', ['risk' => 0.95]);

        Event::assertDispatched(SecurityActionTaken::class, fn (SecurityActionTaken $taken) => $taken->user->is($user)
            && $taken->action === SecurityAction::RevokeSessions
            && $taken->requested === SecurityAction::RevokeSessions
            && $taken->source === SecurityActionTaken::SOURCE_AEGIS);
    }

    public function test_monitor_mode_takes_no_response_so_no_handler_runs(): void
    {
        $user = User::factory()->create();
        $handlerRan = false;
        Security::onAction(SecurityAction::RevokeSessions, function () use (&$handlerRan): void {
            $handlerRan = true;
        });

        Security::recordFor($user, 'test.action', ['risk' => 0.95]);

        $this->assertFalse($handlerRan);
    }

    public function test_a_minimum_action_raises_the_response_in_enforce_mode(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        Security::define(new SecurityEventDefinition('test.payout', 'testing', 'Payout', 'Used by tests.', minimumAction: SecurityAction::Notify));
        $user = User::factory()->create();

        $event = Security::recordFor($user, 'test.payout');

        $this->assertSame(0, $event->risk_score);
        $this->assertSame('notify', $event->recommended_action);
        $this->assertSame('notify', $event->action_taken);
        Notification::assertSentTo($user, EventNotification::class);
    }

    public function test_a_minimum_action_in_monitor_mode_shapes_decisions_without_responding(): void
    {
        Security::define(new SecurityEventDefinition('test.payout', 'testing', 'Payout', 'Used by tests.', minimumAction: SecurityAction::StepUp));
        $user = User::factory()->create();

        $decision = Security::check('test.payout', $user);

        $this->assertTrue($decision->requiresFreshTwoFactor());
        $this->assertNull($decision->event->action_taken);
    }

    public function test_a_minimum_action_never_lets_an_unverified_actor_trigger_more_than_an_alert(): void
    {
        Notification::fake();
        Setting::store(SecuritySettings::MODE, 'enforce');
        Security::define(new SecurityEventDefinition('test.payout_failed', 'testing', 'Payout failed', 'Used by tests.',
            isFailure: true, actorIsVerified: false, minimumAction: SecurityAction::Suspend));
        $user = User::factory()->create();

        $event = Security::recordFor($user, 'test.payout_failed');

        $this->assertSame('notify', $event->action_taken);
        $this->assertFalse($user->fresh()->isSuspended());
    }

    public function test_none_is_not_a_valid_minimum_action(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SecurityEventDefinition('test.payout', 'testing', 'Payout', 'Used by tests.', minimumAction: SecurityAction::None);
    }

    protected function tearDown(): void
    {
        FreezeWallet::$frozen = [];

        parent::tearDown();
    }
}

class FreezeWallet
{
    /** @var list<int> */
    public static array $frozen = [];

    public function handle(SecurityActionTaken $taken): void
    {
        self::$frozen[] = $taken->user->id;
    }
}
