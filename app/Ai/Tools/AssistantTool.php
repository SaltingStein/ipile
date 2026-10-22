<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantTools;
use App\Services\Assistant\BlockedDataAccess;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A read-only tool of the site assistant, always acting for one signed-in person. Its data access runs under
 * {@see AssistantDataPolicy}, so protected tables and columns cannot be read even by mistake; such a query is
 * refused before it runs and the model is told the data is not available.
 *
 * Builders add their own tools by extending this class and registering it with
 * {@see AssistantTools::register()}. {@see handle()} is final so every tool keeps the policy.
 *
 * @api
 */
abstract class AssistantTool implements Tool
{
    /**
     * What the model is told when the data policy refused a tool's query.
     */
    public const NOT_AVAILABLE = 'This information is not available to the assistant.';

    public function __construct(protected User $user) {}

    /**
     * Read the data the model asked for. Return plain data; it is sent to the model as JSON.
     *
     * @return array<mixed>|string
     */
    abstract protected function read(Request $request): array|string;

    final public function handle(Request $request): Stringable|string
    {
        try {
            $result = AssistantDataPolicy::run(fn () => $this->read($request));
        } catch (BlockedDataAccess $exception) {
            report($exception);

            return self::NOT_AVAILABLE;
        }

        return is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
