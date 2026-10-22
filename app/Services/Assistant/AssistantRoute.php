<?php

namespace App\Services\Assistant;

/**
 * How {@see AssistantIntentRouter} routed one message: what it was about ({@see $intent}) and which mode answers
 * ({@see $mode}). They differ when the person may not use the staff mode the message was about; it then falls back
 * to the product-facing assistant.
 */
final readonly class AssistantRoute
{
    public function __construct(
        public AssistantIntent $intent,
        public AssistantIntent $mode,
        public string $channel,
    ) {}

    public function isFallback(): bool
    {
        return $this->intent !== $this->mode;
    }

    /**
     * Context for the Aegis audit event: names only, never the message.
     *
     * @return array{intent: string, mode: string, channel: string, fallback: bool}
     */
    public function toContext(): array
    {
        return ['intent' => $this->intent->value, 'mode' => $this->mode->value, 'channel' => $this->channel, 'fallback' => $this->isFallback()];
    }
}
