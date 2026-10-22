<?php

namespace Tests\Feature\Services\Security;

use App\Models\User;
use App\Services\Security\Security;
use App\Services\Security\SecurityDecision;
use App\Services\Security\SecurityEventDefinition;
use App\Services\Security\SecurityOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/**
 * Covers Security::fake(), the in-memory stand-in builders use to test their Aegis integrations.
 */
class SecurityFakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Security::define(new SecurityEventDefinition('test.payout', 'testing', 'Payout', 'Used by tests.', sensitivity: 1.0));
        Route::middleware(['web', 'auth'])->post('/_test/payout', function () {
            Security::check('test.payout', context: ['amount_kobo' => (int) request('amount_kobo')])->authorize();

            return 'paid';
        });
        Route::middleware(['web', 'auth', 'security.track:test.payout'])->post('/_test/tracked', fn () => 'ok');
    }

    public function test_records_and_checks_are_kept_in_memory_instead_of_being_scored(): void
    {
        $security = Security::fake();
        $user = User::factory()->create();

        $event = Security::recordFor($user, 'test.payout', ['amount_kobo' => 500]);
        $decision = Security::check('test.payout', $user);

        $this->assertNull($event);
        $this->assertSame(SecurityOutcome::Allow, $decision->outcome);
        $this->assertDatabaseCount('security_events', 0);
        $security->assertRecorded('test.payout', fn (?User $recordedFor, array $context) => $recordedFor->is($user) && $context === ['amount_kobo' => 500]);
        $security->assertChecked('test.payout');
    }

    public function test_a_forced_decision_drives_the_real_request_flow(): void
    {
        $security = Security::fake()->decide('test.payout', SecurityDecision::challenge());
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->post('/_test/payout', ['amount_kobo' => 750]);

        $response->assertRedirect(route('security.confirm'));
        $security->assertChecked('test.payout', fn (?User $checkedFor, array $context) => $context['amount_kobo'] === 750);
    }

    public function test_previews_are_not_recorded(): void
    {
        $security = Security::fake()->decide('test.payout', SecurityDecision::deny());

        $decision = Security::preview('test.payout', User::factory()->create());

        $this->assertTrue($decision->denied());
        $this->assertTrue($decision->isPreview);
        $this->assertSame('test.payout', $decision->type);
        $security->assertPreviewed('test.payout');
        $security->assertNothingRecorded();
    }

    public function test_route_tracking_goes_through_the_fake(): void
    {
        $security = Security::fake();

        $this->actingAs(User::factory()->create())->post('/_test/tracked')->assertOk();

        $security->assertRecorded('test.payout');
    }

    public function test_unknown_event_types_still_fail_loudly(): void
    {
        Security::fake();

        $this->expectException(InvalidArgumentException::class);

        Security::record('test.typo');
    }

    public function test_assertions_fail_when_nothing_matches(): void
    {
        $security = Security::fake();

        $this->expectException(ExpectationFailedException::class);

        $security->assertRecorded('test.payout');
    }
}
