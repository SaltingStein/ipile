<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * A message the site assistant will not answer (daily limit, refused request, AI service unavailable). Already
 * recorded in the interaction log; the controller only sends {@see $payload} with {@see $status}.
 */
class AssistantChatStopped extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly int $status, public readonly array $payload)
    {
        parent::__construct((string) ($payload['message'] ?? 'The assistant did not answer.'));
    }
}
