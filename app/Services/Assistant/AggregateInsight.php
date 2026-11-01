<?php

namespace App\Services\Assistant;

use App\Ai\Insights\Insight;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Declarative, aggregate-only insight. Queries and scope are enforced by the registry.
 */
abstract class AggregateInsight implements Insight
{
    public function __construct(private ?User $user = null) {}

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function permission(): ?string;

    abstract public function table(): string;

    public function scope(): string
    {
        return 'platform';
    }

    public function teamAbility(): string
    {
        return 'view';
    }

    public function safeForAll(): bool
    {
        return false;
    }

    public function periodColumn(): string
    {
        return 'created_at';
    }

    public function trend(): bool
    {
        return false;
    }

    public function summaryKey(): ?string
    {
        return null;
    }

    public function sumColumn(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function groupBy(): array
    {
        return [];
    }

    /** @return array<string, string|int|bool> */
    public function filters(): array
    {
        return [];
    }

    public function description(): Stringable|string
    {
        return $this->label().'. Read-only aggregates for the last 30 days, limited to 50 groups; never individual records.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    final public function handle(Request $request): Stringable|string
    {
        return app(InsightRegistry::class)->read($this, $this->user);
    }
}
