<?php

namespace App\Services\Assistant;

use App\Ai\Agents\SiteAssistant;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\Message;

/**
 * A screened, routed message that the site assistant is about to answer ({@see AssistantChat::begin()}).
 */
final readonly class AssistantTurn
{
    /**
     * @param  list<Message>  $history
     * @param  array{modes: list<string>, tools: list<string>, insights: list<string>}  $capabilities
     */
    public function __construct(
        public User $user,
        public string $message,
        public string $channel,
        public AssistantConversation $conversation,
        public AssistantMessage $userMessage,
        public AssistantRoute $route,
        public array $history,
        public TextProvider $provider,
        public string $providerKey,
        public array $capabilities,
        public float $startedAt,
    ) {}

    public function agent(): SiteAssistant
    {
        return new SiteAssistant($this->user, $this->history, $this->route->mode);
    }
}
