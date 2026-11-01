<?php

namespace App\Services\Assistant;

use App\Listeners\RecordAssistantToolCall;
use App\Models\AiInteraction;
use Laravel\Ai\Events\ToolInvoked;

/**
 * Collects the tools the site assistant called while one request is answered, for the interaction log
 * ({@see AiInteraction}). Only the tool, its argument names with short scrubbed values and the size of
 * the result are kept: never the result itself. Fed by {@see RecordAssistantToolCall}; collects nothing
 * outside {@see start()} / {@see stop()}, so other agents' tools are never recorded. Registered as a singleton.
 */
class AssistantToolLog
{
    private const VALUE_LENGTH = 80;

    private bool $active = false;

    /** @var list<array{tool: string, arguments: array<string, string>, result_bytes: int}> */
    private array $calls = [];

    public function __construct(private AssistantGuard $guard) {}

    public function start(): void
    {
        $this->active = true;
        $this->calls = [];
    }

    /**
     * @return list<array{tool: string, arguments: array<string, string>, result_bytes: int}>
     */
    public function stop(): array
    {
        $calls = $this->calls;
        $this->active = false;
        $this->calls = [];

        return $calls;
    }

    public function record(ToolInvoked $event): void
    {
        if (! $this->active) {
            return;
        }

        $arguments = [];

        foreach ($event->arguments as $name => $value) {
            $text = is_scalar($value) ? (string) $value : (string) json_encode($value);
            // Emails and phone numbers someone typed into a search are masked as well as anything key-like.
            $text = (string) preg_replace(['/[^\s@]+@[^\s@]+/', '/\+?\d[\d\s-]{7,}\d/'], ['[email]', '[number]'], $this->guard->scrub($text));
            $arguments[(string) $name] = mb_substr($text, 0, self::VALUE_LENGTH);
        }

        $result = $event->result;

        $this->calls[] = [
            'tool' => class_basename($event->tool),
            'arguments' => $arguments,
            'result_bytes' => strlen(is_string($result) || $result instanceof \Stringable ? (string) $result : (string) json_encode($result)),
        ];
    }
}
