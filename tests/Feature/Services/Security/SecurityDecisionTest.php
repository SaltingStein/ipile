<?php

namespace Tests\Feature\Services\Security;

use App\Models\Setting;
use App\Models\User;
use App\Services\Security\FreshTwoFactor;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventDefinition;
use App\Services\Security\SecurityOutcome;
use App\Services\Security\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers pre-action checks ({@see Security::check()}), read-only previews and how decisions are enforced.
 */
class SecurityDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Scores 100 × the `risk` context value (default thresholds: alert 60, password re-check 75, sign out 90).
        Security::define(new SecurityEventDefinition('test.action', 'testing', 'Test action', 'Used by tests.', sensitivity: 1.0));
        Security::signal(ContextRiskSignal::class);

        Route::middleware(['web', 'auth'])->post('/_test/payout', function () {
            Security::check('test.action', context: ['risk' => (float) request('risk')])->authorize();

            return 'paid';
        });
    }

    public function test_a_low_risk_action_is_allowed_and_recorded(): void
    {
        $user = User::factory()->create();

        $decision = Security::check('test.action', $user, ['risk' => 0.3]);

        $this->assertSame(SecurityOutcome::Allow, $decision->outcome);
        $this->assertSame(30, $decision->score);
        $this->assertModelExists($decision->event);
    }

    public function test_a_risky_action_needs_a_fresh_two_factor_code(): void
    {
        $decision = Security::check('test.action', User::factory()->create(), ['risk' => 0.8]);

        $this->assertTrue($decision->requiresFreshTwoFactor());
        $this->assertSame(SecurityAction::StepUp, $decision->action);
    }

    public function test_a_very_risky_action_is_denied(): void
    {
        $decision = Security::check('test.action', User::factory()->create(), ['risk' => 0.95]);

        $this->assertTrue($decision->denied());
        $this->assertSame(SecurityAction::RevokeSessions, $decision->action);
    }

    public function test_checks_fail_open_while_aegis_is_off(): void
    {
        Setting::store(SecuritySettings::MODE, 'off');

        $decision = Security::check('test.action', User::factory()->create(), ['risk' => 0.95]);

        $this->assertTrue($decision->allowed());
        $this->assertNull($decision->event);
        $this->assertDatabaseCount('security_events', 0);
    }

    public function test_events_with_a_forced_score_are_still_decided_while_aegis_is_off(): void
    {
        Setting::store(SecuritySettings::MODE, 'off');
        Security::define(new SecurityEventDefinition('test.forced', 'testing', 'Forced', 'Used by tests.', forcedScore: 95));

        $decision = Security::check('test.forced', User::factory()->create());

        $this->assertTrue($decision->denied());
        $this->assertModelExists($decision->event);
    }

    public function test_preview_gives_the_decision_without_recording_or_changing_anything(): void
    {
        $user = User::factory()->create();

        $decision = Security::preview('test.action', $user, ['risk' => 0.95]);

        $this->assertTrue($decision->denied());
        $this->assertSame(95, $decision->score);
        $this->assertTrue($decision->isPreview);
        $this->assertNull($decision->event);
        $this->assertDatabaseCount('security_events', 0);
        $this->assertDatabaseCount('security_incidents', 0);
        $this->assertDatabaseCount('security_risk_states', 0);
    }

    public function test_a_challenge_sends_browsers_to_confirm_and_comes_back_afterwards(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->from('/dashboard')->post('/_test/payout', ['risk' => 0.8]);

        $response->assertRedirect(route('security.confirm'));
        $response->assertSessionHas('url.intended', url('/dashboard'));
    }

    public function test_a_challenge_answers_json_callers_with_423_and_the_confirm_url(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->postJson('/_test/payout', ['risk' => 0.8]);

        $response->assertStatus(423)->assertJson(['confirm_url' => route('security.confirm')]);
    }

    public function test_someone_who_just_confirmed_is_not_challenged_again(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)
            ->withSession([FreshTwoFactor::SESSION_KEY => now()->getTimestamp()])
            ->post('/_test/payout', ['risk' => 0.8]);

        $response->assertOk()->assertSee('paid');
    }

    public function test_a_denial_goes_back_with_the_reason(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from('/dashboard')->post('/_test/payout', ['risk' => 0.95]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHasErrors(['security' => 'For your security, this action has been blocked. Contact support if you need help.']);
    }

    public function test_a_denial_answers_json_callers_with_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/_test/payout', ['risk' => 0.95]);

        $response->assertForbidden();
    }
}
