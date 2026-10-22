<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantMetrics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Business figures an administrator allowed in Admin → Site assistant, limited to the ones this person's own
 * permissions cover (see AssistantMetrics). Counts and totals only.
 */
class BusinessFigures extends AssistantTool
{
    /**
     * @var array<string, array{label: string, description: string, permission: string}>
     */
    private array $available;

    public function __construct(User $user, private AssistantMetrics $metrics)
    {
        parent::__construct($user);

        // Worked out before any tool runs, as the settings it needs are outside what the tools may read.
        $this->available = $metrics->availableTo($user);
    }

    public function description(): Stringable|string
    {
        $list = collect($this->available)->map(fn (array $metric, string $key) => "{$key}: {$metric['description']}")->implode('; ');

        return 'Read a business figure (counts and totals, never personal details). Available to this person: '.($list ?: 'none').'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'figure' => $schema->string()->enum(array_keys($this->available) ?: ['none'])->required(),
        ];
    }

    protected function read(Request $request): array|string
    {
        $key = (string) $request['figure'];

        if (! isset($this->available[$key])) {
            return 'That figure is not available to this person. Do not guess it.';
        }

        return ['figure' => $this->available[$key]['label'], 'as_of' => now()->toDayDateTimeString(), 'values' => $this->metrics->values($key)];
    }
}
