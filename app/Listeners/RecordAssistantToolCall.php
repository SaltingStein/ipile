<?php

namespace App\Listeners;

use App\Services\Assistant\AssistantToolLog;
use Laravel\Ai\Events\ToolInvoked;

/**
 * Passes tool calls to the site assistant's interaction log while a request is being answered.
 */
class RecordAssistantToolCall
{
    public function __construct(private AssistantToolLog $log) {}

    public function handle(ToolInvoked $event): void
    {
        $this->log->record($event);
    }
}
