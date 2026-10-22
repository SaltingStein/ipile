<?php

namespace App\Services\Security;

use App\Events\Security\SecurityEventRecorded;
use App\Events\Security\SecurityRiskAssessed;
use App\Jobs\AnalyzeSecurityIncident;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityRiskState;
use App\Models\User;
use App\Services\Security\Analysis\SecurityAnalyst;
use App\Services\Security\Markov\BaselineTrainer;
use App\Services\Security\Markov\RiskPostureChain;
use App\Services\Security\Signals\SignalContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records, scores and responds to one security event. Use {@see Security::record()} rather than calling this directly.
 *
 * Steps: check mode and scope → score the signals → move the account's posture chain → save the event → open or
 * update an incident → respond (enforce mode only, and only when the response is stronger than one already taken)
 * → announce {@see SecurityRiskAssessed} → queue AI analysis when configured.
 *
 * {@see check()} runs the same steps and turns the result into a {@see SecurityDecision}; {@see preview()} scores
 * without saving or changing anything.
 *
 * Monitoring fails open: if anything here throws, the error is reported and the user's request carries on.
 */
class SecurityMonitor
{
    /** Posture below this ends an episode: the next risky event may trigger responses again. */
    private const EPISODE_RESET_SCORE = 20;

    private bool $isRecording = false;

    public function __construct(
        private SecuritySettings $settings,
        private SecurityEventCatalog $catalog,
        private RiskAssessor $assessor,
        private RiskPostureChain $posture,
        private IncidentManager $incidents,
        private SecurityResponder $responder,
        private SecurityAnalyst $analyst,
        private FreshTwoFactor $freshTwoFactor,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Small, non-secret details (amounts, ids, counts). Scalars only.
     *
     * @throws \InvalidArgumentException when the event type is not registered (always, so typos surface in development).
     */
    public function record(string $type, ?User $user = null, array $context = []): ?SecurityEvent
    {
        $definition = $this->catalog->find($type);
        $mode = $this->settings->mode();

        // Responses can trigger framework events (e.g. Logout) that would otherwise be recorded mid-assessment.
        if ($this->isRecording || ! $this->isInScope($definition, $mode)) {
            return null;
        }

        $this->isRecording = true;

        try {
            return $this->process($definition, $mode, $user, $this->sanitise($context));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            $this->isRecording = false;
        }
    }

    /**
     * Record the event as usual, then decide whether the action it describes should go ahead.
     *
     * @param  array<string, mixed>  $context
     */
    public function check(string $type, ?User $user = null, array $context = []): SecurityDecision
    {
        $definition = $this->catalog->find($type);
        $event = $this->record($type, $user, $context);

        if ($event === null) {
            return $this->unscoredDecision($definition, $user);
        }

        return $this->decide(
            $definition,
            $user,
            max($event->risk_score, $event->posture_score),
            SecurityAction::tryFrom((string) $event->recommended_action) ?? SecurityAction::None,
            $event,
        );
    }

    /**
     * The decision {@see check()} would give now, without recording the event, moving the account's posture,
     * opening an incident or responding.
     *
     * @param  array<string, mixed>  $context
     */
    public function preview(string $type, ?User $user = null, array $context = []): SecurityDecision
    {
        $definition = $this->catalog->find($type);

        if ($this->isRecording || ! $this->isInScope($definition, $this->settings->mode())) {
            return $this->unscoredDecision($definition, $user, isPreview: true);
        }

        try {
            $evaluation = $this->evaluate($definition, $user, $this->sanitise($context), now());
        } catch (Throwable $exception) {
            report($exception);

            return $this->unscoredDecision($definition, $user, isPreview: true);
        }

        return $this->decide($definition, $user, $evaluation['decision_score'], $evaluation['recommended'], null, isPreview: true);
    }

    /**
     * Events with a forced score are high risk by nature and are in scope whatever the mode and category settings.
     */
    private function isInScope(SecurityEventDefinition $definition, SecurityMode $mode): bool
    {
        return $definition->forcedScore !== null
            || ($mode->isRecording() && $this->settings->isCategoryMonitored($definition->category));
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function process(SecurityEventDefinition $definition, SecurityMode $mode, ?User $user, array $context): SecurityEvent
    {
        $evaluation = $this->evaluate($definition, $user, $context, now());
        $signalContext = $evaluation['signal_context'];
        $state = $evaluation['state'];
        $recommended = $evaluation['recommended'];
        $decisionScore = $evaluation['decision_score'];
        $userAgent = request()->userAgent();

        $state?->save();

        $event = SecurityEvent::query()->create([
            'user_id' => $user?->getKey(),
            'type' => $definition->key,
            'category' => $definition->category,
            'ip_address' => $signalContext->ipAddress,
            'device_hash' => $signalContext->deviceHash,
            'network_hash' => $signalContext->networkHash,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
            'context' => $context === [] ? null : $context,
            'risk_score' => $evaluation['event_score'],
            'posture_score' => $state?->score ?? 0,
            'signals' => $evaluation['signals'],
            'recommended_action' => $recommended === SecurityAction::None ? null : $recommended->value,
            'occurred_at' => $signalContext->occurredAt,
        ]);

        $this->dispatchSafely(new SecurityEventRecorded($event));

        $incident = $definition->forcedScore !== null || $decisionScore >= $this->settings->incidentThreshold()
            ? $this->incidents->capture($event, $decisionScore, $recommended)
            : null;

        // Events with a forced score are answered by the code that recorded them.
        if ($definition->forcedScore === null && $mode === SecurityMode::Enforce && $user !== null && ! $user->is_guest && $state !== null
            && $recommended->isStrongerThan(SecurityAction::tryFrom((string) $state->last_action))) {
            $taken = $this->responder->respond($user, $recommended, $event, $incident);
            $event->forceFill(['action_taken' => $taken->value])->save();
            $state->forceFill(['last_action' => $recommended->value])->save();

            if ($incident !== null) {
                $this->incidents->recordAction($incident, $taken);
            }
        }

        $this->dispatchSafely(new SecurityRiskAssessed($event, $decisionScore, $incident));

        if ($incident !== null) {
            $this->queueAnalysis($incident);
        }

        return $event;
    }

    /**
     * Score the event and project the account's posture, without saving anything.
     *
     * @param  array<string, scalar|null>  $context
     * @return array{signal_context: SignalContext, signals: list<array{key: string, label: string, score: float, weight: float, detail: ?string}>, event_score: int, state: SecurityRiskState|null, decision_score: int, recommended: SecurityAction}
     */
    private function evaluate(SecurityEventDefinition $definition, ?User $user, array $context, CarbonInterface $occurredAt): array
    {
        $request = request();
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();

        $previousEvent = $user === null ? null : SecurityEvent::query()
            ->where('user_id', $user->getKey())
            ->where('occurred_at', '>=', $occurredAt->copy()->subMinutes(BaselineTrainer::SESSION_GAP_MINUTES))
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        $signalContext = new SignalContext(
            $definition,
            $user,
            $ipAddress,
            $userAgent === null || $userAgent === '' ? null : hash('sha256', $userAgent),
            $this->networkHash($ipAddress),
            $occurredAt,
            $previousEvent,
            $context,
        );

        $assessment = $this->assessor->assess($signalContext);
        // Events with a forced score move the posture chain as if their signals had scored that much.
        $eventScore = max($assessment['score'], $definition->forcedScore ?? 0);
        $state = $user === null ? null : $this->projectPosture($user, $eventScore);
        $decisionScore = max($eventScore, $state?->score ?? 0);

        return [
            'signal_context' => $signalContext,
            'signals' => $assessment['signals'],
            'event_score' => $eventScore,
            'state' => $state,
            'decision_score' => $decisionScore,
            'recommended' => $this->recommend($definition, $decisionScore),
        ];
    }

    /**
     * The response for a score: the configured threshold, raised to the event's minimum action. Unverified actors
     * (e.g. a failed sign-in anyone can cause) never get more than an alert to the owner.
     */
    private function recommend(SecurityEventDefinition $definition, int $decisionScore): SecurityAction
    {
        $recommended = $this->settings->actionFor($decisionScore);

        if ($definition->minimumAction !== null && $definition->minimumAction->isStrongerThan($recommended)) {
            $recommended = $definition->minimumAction;
        }

        if (! $definition->actorIsVerified && $recommended->isStrongerThan(SecurityAction::Notify)) {
            $recommended = SecurityAction::Notify;
        }

        return $recommended;
    }

    private function decide(
        SecurityEventDefinition $definition,
        ?User $user,
        int $score,
        SecurityAction $action,
        ?SecurityEvent $event,
        bool $isPreview = false,
    ): SecurityDecision {
        $outcome = match (true) {
            $action->rank() >= SecurityAction::RevokeSessions->rank() => SecurityOutcome::Deny,
            $action === SecurityAction::StepUp => SecurityOutcome::Challenge,
            default => SecurityOutcome::Allow,
        };
        $reason = match ($outcome) {
            SecurityOutcome::Deny => SecurityDecision::denyReason(),
            SecurityOutcome::Challenge => SecurityDecision::challengeReason(),
            SecurityOutcome::Allow => __('Allowed.'),
        };

        // Someone who has just entered a fresh code in this browser is not asked again.
        if ($outcome === SecurityOutcome::Challenge && $user !== null && Auth::id() === $user->getKey()
            && $user->hasEnabledTwoFactorAuthentication() && $this->freshTwoFactor->confirmedWithin()) {
            $outcome = SecurityOutcome::Allow;
            $reason = __('Allowed: confirmed with the authenticator app a moment ago.');
        }

        return new SecurityDecision($outcome, $definition->key, $score, $action, $reason, $event, $isPreview);
    }

    /**
     * Nothing was scored (Aegis is off, the category is switched off, or scoring failed): allow, failing open, except
     * for events with a forced score, which are decided from that score.
     */
    private function unscoredDecision(SecurityEventDefinition $definition, ?User $user, bool $isPreview = false): SecurityDecision
    {
        if ($definition->forcedScore === null) {
            return new SecurityDecision(SecurityOutcome::Allow, $definition->key, 0, SecurityAction::None, __('Allowed.'), null, $isPreview);
        }

        return $this->decide($definition, $user, $definition->forcedScore, $this->recommend($definition, $definition->forcedScore), null, $isPreview);
    }

    /**
     * Decay the account's posture for the quiet time since its last event, then apply this event. Not saved.
     */
    private function projectPosture(User $user, int $eventScore): SecurityRiskState
    {
        $state = SecurityRiskState::query()->firstOrNew(['user_id' => $user->getKey()]);
        $belief = $this->posture->normalise($state->belief);

        if ($state->last_event_at !== null) {
            $belief = $this->posture->decay($belief, $state->last_event_at->diffInMinutes(now(), true));
        }

        $belief = $this->posture->observe($belief, $eventScore);
        $score = $this->posture->score($belief);

        return $state->fill([
            'belief' => $belief,
            'score' => $score,
            'level' => RiskPostureChain::levelFor($score),
            'last_event_at' => now(),
            'last_action' => $score < self::EPISODE_RESET_SCORE ? null : $state->last_action,
        ]);
    }

    /**
     * A broken listener must not stop incidents or responses.
     */
    private function dispatchSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function queueAnalysis(SecurityIncident $incident): void
    {
        if ($incident->ai_status !== null
            || ! $this->settings->aiEnabled()
            || $incident->risk_score < $this->settings->aiThreshold()
            || ! $this->analyst->isAvailable()) {
            return;
        }

        $incident->forceFill(['ai_status' => SecurityIncident::AI_PENDING])->save();
        AnalyzeSecurityIncident::dispatch($incident->getKey());
    }

    /**
     * Hash of the network the address belongs to: IPv4 /24 or IPv6 /48.
     */
    private function networkHash(?string $ipAddress): ?string
    {
        $packed = $ipAddress === null ? false : @inet_pton($ipAddress);

        if ($packed === false) {
            return null;
        }

        return hash('sha256', strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 6));
    }

    /**
     * Keep at most 20 scalar values with short keys and strings, so context can never balloon or carry objects.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|null>
     */
    private function sanitise(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (count($clean) >= 20 || ! is_string($key) || ! (is_scalar($value) || $value === null)) {
                continue;
            }

            $clean[Str::limit($key, 50, '')] = is_string($value) ? Str::limit($value, 200) : $value;
        }

        return $clean;
    }
}
