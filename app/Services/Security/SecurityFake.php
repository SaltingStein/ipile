<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Stands in for Aegis in your tests, from {@see Security::fake()}. Records and checks are kept in memory instead of
 * being scored: nothing is saved, no incident opens and no response is taken. Unknown event types still throw, as
 * they do for real, so typos surface in tests.
 *
 *     $security = Security::fake()->decide('payouts.requested', SecurityDecision::challenge());
 *
 *     $this->actingAs($user)->post('/payouts', ['amount_kobo' => 500_000])->assertRedirect(route('security.confirm'));
 *
 *     $security->assertChecked('payouts.requested', fn (?User $user, array $context) => $context['amount_kobo'] === 500_000);
 *
 * Checks are allowed unless you force a decision with {@see decide()}.
 *
 * @api
 */
class SecurityFake extends SecurityMonitor
{
    /** @var list<array{type: string, user: User|null, context: array<string, mixed>}> */
    private array $recorded = [];

    /** @var list<array{type: string, user: User|null, context: array<string, mixed>}> */
    private array $checked = [];

    /** @var list<array{type: string, user: User|null, context: array<string, mixed>}> */
    private array $previewed = [];

    /** @var array<string, SecurityDecision> */
    private array $decisions = [];

    public function __construct(private SecurityEventCatalog $events) {}

    /**
     * Make every check and preview of this event type return the decision.
     */
    public function decide(string $type, SecurityDecision $decision): static
    {
        $this->events->find($type);
        $this->decisions[$type] = $decision;

        return $this;
    }

    public function record(string $type, ?User $user = null, array $context = []): ?SecurityEvent
    {
        $this->events->find($type);
        $this->recorded[] = ['type' => $type, 'user' => $user, 'context' => $context];

        return null;
    }

    public function check(string $type, ?User $user = null, array $context = []): SecurityDecision
    {
        $this->record($type, $user, $context);
        $this->checked[] = ['type' => $type, 'user' => $user, 'context' => $context];

        return $this->decisionFor($type, isPreview: false);
    }

    public function preview(string $type, ?User $user = null, array $context = []): SecurityDecision
    {
        $this->events->find($type);
        $this->previewed[] = ['type' => $type, 'user' => $user, 'context' => $context];

        return $this->decisionFor($type, isPreview: true);
    }

    /**
     * @param  (Closure(User|null, array<string, mixed>): bool)|null  $callback
     */
    public function assertRecorded(string $type, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->matching($this->recorded, $type, $callback), "The security event [{$type}] was not recorded".($callback === null ? '.' : ' with the expected details.'));
    }

    public function assertNotRecorded(string $type): void
    {
        PHPUnit::assertEmpty($this->matching($this->recorded, $type, null), "The security event [{$type}] was recorded.");
    }

    public function assertNothingRecorded(): void
    {
        PHPUnit::assertEmpty($this->recorded, 'Security events were recorded: '.implode(', ', array_column($this->recorded, 'type')).'.');
    }

    /**
     * @param  (Closure(User|null, array<string, mixed>): bool)|null  $callback
     */
    public function assertChecked(string $type, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->matching($this->checked, $type, $callback), "The action [{$type}] was not checked with Security::check()".($callback === null ? '.' : ' with the expected details.'));
    }

    public function assertNotChecked(string $type): void
    {
        PHPUnit::assertEmpty($this->matching($this->checked, $type, null), "The action [{$type}] was checked with Security::check().");
    }

    /**
     * @param  (Closure(User|null, array<string, mixed>): bool)|null  $callback
     */
    public function assertPreviewed(string $type, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->matching($this->previewed, $type, $callback), "The action [{$type}] was not previewed with Security::preview().");
    }

    /**
     * Everything recorded so far, oldest first.
     *
     * @return list<array{type: string, user: User|null, context: array<string, mixed>}>
     */
    public function recorded(?string $type = null): array
    {
        return $type === null ? $this->recorded : $this->matching($this->recorded, $type, null);
    }

    private function decisionFor(string $type, bool $isPreview): SecurityDecision
    {
        $forced = $this->decisions[$type] ?? SecurityDecision::allow($type);

        return new SecurityDecision($forced->outcome, $type, $forced->score, $forced->action, $forced->reason, null, $isPreview);
    }

    /**
     * @param  list<array{type: string, user: User|null, context: array<string, mixed>}>  $entries
     * @param  (Closure(User|null, array<string, mixed>): bool)|null  $callback
     * @return list<array{type: string, user: User|null, context: array<string, mixed>}>
     */
    private function matching(array $entries, string $type, ?Closure $callback): array
    {
        return array_values(array_filter(
            $entries,
            fn (array $entry) => $entry['type'] === $type && ($callback === null || $callback($entry['user'], $entry['context'])),
        ));
    }
}
