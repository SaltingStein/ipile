<?php

namespace App\Mcp\Tools;

use App\Ai\Tools\AssistantTool;
use App\Models\User;
use App\Services\Assistant\WidgetDataTools;
use App\Services\Security\Security;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as AssistantRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * Offers one site assistant tool ({@see AssistantTool}) over MCP, acting for the person the widget's claim belongs
 * to. The tool's own data policy still applies. Every call is recorded as `mcp.tool_called` (tool and outcome only),
 * and errors never reach the caller, whatever `app.debug` says: they would describe tables and columns.
 *
 * Built by {@see WidgetDataTools}; not registered on a server directly.
 */
#[IsReadOnly]
#[IsIdempotent]
class WidgetDataTool extends Tool
{
    public const FAILED = 'This tool could not be run.';

    public function __construct(private AssistantTool $tool, private User $user)
    {
        $this->name = Str::kebab(class_basename($tool));
        $this->title = Str::headline(class_basename($tool));
        $this->description = (string) $tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Response
    {
        try {
            $result = (string) $this->tool->handle(new AssistantRequest($request->all()));
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCall('error');

            return Response::error(self::FAILED);
        }

        $this->recordCall($result === AssistantTool::NOT_AVAILABLE ? 'blocked' : 'ok');

        return Response::text($result);
    }

    /**
     * @param  'ok'|'blocked'|'error'  $outcome
     */
    private function recordCall(string $outcome): void
    {
        Security::recordFor($this->user, 'mcp.tool_called', ['tool' => $this->name, 'outcome' => $outcome]);
    }
}
