<?php

namespace App\Services\Assistant;

use App\Ai\Agents\SiteAssistant;
use App\Models\AiInteraction;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiModels;
use App\Services\Integrations\IntegrationManager;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Everything around one message to the built-in site assistant, shared by its page and its chat bubble: the daily
 * limit, screening ({@see AssistantGuard}), routing ({@see AssistantIntentRouter}), the person's own conversation, and
 * one append-only {@see AiInteraction} row per request, whatever the outcome. The caller only prompts (or streams)
 * {@see AssistantTurn::agent()} and hands the reply to {@see complete()}, or reports a failure with {@see failed()}.
 */
class AssistantChat
{
    public const HISTORY_MESSAGES = 12;

    /** After this many messages past the daily limit in one day, Aegis records `assistant.rate_limited`. */
    public const RATE_LIMIT_ALERT_AFTER = 3;

    public function __construct(
        private AssistantSettings $settings,
        private AssistantGuard $guard,
        private AssistantIntentRouter $router,
        private AiModels $models,
        private IntegrationManager $integrations,
        private AssistantToolLog $tools,
        private InsightRegistry $insights,
    ) {}

    /**
     * Screen and route a message, and store it in the person's conversation (a new one when null).
     *
     * @throws AssistantChatStopped when the message will not be answered; the interaction is already logged.
     */
    public function begin(User $user, string $message, ?AssistantConversation $conversation, string $channel): AssistantTurn
    {
        $startedAt = microtime(true);
        $usageKey = 'assistant:messages:'.$user->id.':'.now()->toDateString();

        if ((int) Cache::get($usageKey, 0) >= $this->settings->dailyMessages()) {
            $this->noteRateLimit($user);
            $this->log($user, $channel, AiInteraction::RATE_LIMITED, $startedAt, conversation: $conversation);

            throw new AssistantChatStopped(429, ['message' => __('You have reached today’s limit of :count messages. Try again tomorrow.', ['count' => $this->settings->dailyMessages()])]);
        }

        Cache::add($usageKey, 0, now()->endOfDay());
        Cache::increment($usageKey);

        $history = $conversation === null ? [] : $this->history($conversation);
        $verdict = $this->guard->screen($message, $history);

        if ($verdict->isViolation()) {
            $action = $this->guard->enforce($user, $verdict, $message);
            // The request itself is kept in the Aegis incident, not in the conversation.
            $this->log($user, $channel, AiInteraction::REFUSED, $startedAt, conversation: $conversation);
            $notice = $action === SecurityAction::Suspend
                ? __('The assistant cannot help with passwords, codes, keys or other people’s data. Your account has been suspended for your protection and administrators have been told. Contact an administrator to restore it.')
                : __('The assistant cannot help with passwords, codes, keys or other people’s data. For your protection you have been signed out everywhere and administrators have been told. Sign in again with a code from your authenticator app.');

            throw new AssistantChatStopped(423, ['locked' => true, 'message' => $notice, 'redirect' => route('login')]);
        }

        $providerKey = (string) $this->settings->provider();
        $provider = $verdict->isAllowed() ? $this->models->provider($providerKey) : null;

        if ($provider === null) {
            $this->log($user, $channel, AiInteraction::PROVIDER_ERROR, $startedAt, conversation: $conversation);

            throw new AssistantChatStopped(503, ['message' => match ($verdict->reason) {
                AssistantVerdict::NO_CREDIT => __('The assistant is not available: its AI service has no credit left. Please tell an administrator.'),
                AssistantVerdict::UNREACHABLE => __('The assistant could not reach its AI service to check your message, so it is not answering. Please try again in a moment.'),
                AssistantVerdict::FAILED => __('The assistant’s AI service could not check your message, so it is not answering. Please try again in a moment.'),
                default => __('The assistant is not available: its AI model is switched off. An administrator can choose one in Admin → Site assistant.'),
            }]);
        }

        $route = $this->router->route($user, $message, $channel, $conversation === null ? null : $this->previousIntent($conversation));
        $conversation ??= AssistantConversation::query()->create(['user_id' => $user->id, 'title' => Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 77)]);
        $userMessage = $conversation->messages()->create(['role' => AssistantMessage::ROLE_USER, 'content' => $message, 'intent' => $route->intent->value]);

        $turn = new AssistantTurn($user, $message, $channel, $conversation, $userMessage, $route, $history, $provider, $providerKey,
            $this->capabilities($user, $channel, new SiteAssistant($user, $history, $route->mode)), $startedAt);
        $this->tools->start();

        return $turn;
    }

    /**
     * Store the answer as shown (after the answer check) and log the interaction. Returns null when the answer was empty.
     */
    public function complete(AssistantTurn $turn, string $reply, ?TextUsage $usage = null, ?string $model = null): ?AssistantMessage
    {
        $reply = trim($this->guard->scrub($reply));

        if ($reply === '') {
            $this->failed($turn);

            return null;
        }

        $answer = $turn->conversation->messages()->create([
            'role' => AssistantMessage::ROLE_ASSISTANT, 'content' => $reply,
            'intent' => $turn->route->intent->value, 'mode' => $turn->route->mode->value,
        ]);
        $turn->conversation->touch();

        if ($turn->route->isFallback()) {
            Security::recordFor($turn->user, 'assistant.scope_denied', ['intent' => $turn->route->intent->value, 'channel' => $turn->channel]);
        }

        $this->log($turn->user, $turn->channel, $turn->route->isFallback() ? AiInteraction::DENIED_BY_SCOPE : AiInteraction::ANSWERED, $turn->startedAt,
            turn: $turn, answer: $answer, tools: $this->tools->stop(), usage: $usage, model: $model);

        return $answer;
    }

    /**
     * The AI service failed or returned nothing. The person's message stays in the conversation, unanswered.
     */
    public function failed(AssistantTurn $turn): void
    {
        $this->log($turn->user, $turn->channel, AiInteraction::PROVIDER_ERROR, $turn->startedAt, turn: $turn, tools: $this->tools->stop());
    }

    /**
     * The conversation's latest answered turns, oldest first. An unanswered message (the AI service failed) is left
     * out so turns keep alternating.
     *
     * @return list<Message>
     */
    public function history(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()->reorder('id', 'desc')->limit(self::HISTORY_MESSAGES * 2)->get()->reverse()->values();
        $history = [];

        foreach ($messages as $index => $message) {
            $next = $messages[$index + 1] ?? null;

            if ($message->role === AssistantMessage::ROLE_USER && $next?->role !== AssistantMessage::ROLE_ASSISTANT) {
                continue;
            }

            $history[] = new Message($message->role, $message->content);
        }

        return array_slice($history, -self::HISTORY_MESSAGES);
    }

    private function previousIntent(AssistantConversation $conversation): ?AssistantIntent
    {
        $intent = $conversation->messages()->reorder('id', 'desc')->where('role', AssistantMessage::ROLE_ASSISTANT)->value('intent');

        return AssistantIntent::tryFrom((string) $intent);
    }

    /**
     * What this person could use at this moment: modes, the tools offered for the routed mode and analytics insights.
     *
     * @return array{modes: list<string>, tools: list<string>, insights: list<string>}
     */
    private function capabilities(User $user, string $channel, ?SiteAssistant $agent = null): array
    {
        $modes = [AssistantIntent::EndUserSupport, ...$this->router->staffModesFor($user, $channel)];

        return [
            'modes' => array_map(fn (AssistantIntent $mode) => $mode->value, $modes),
            'tools' => $agent === null ? [] : array_values(array_map('class_basename', iterator_to_array($agent->tools(), false))),
            'insights' => in_array(AssistantIntent::Analytics, $modes, true) ? array_map(fn ($insight) => $insight->key(), $this->insights->for($user)) : [],
        ];
    }

    private function noteRateLimit(User $user): void
    {
        $key = 'assistant:over-limit:'.$user->id.':'.now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());

        if (Cache::increment($key) === self::RATE_LIMIT_ALERT_AFTER) {
            Security::recordFor($user, 'assistant.rate_limited', ['daily_limit' => $this->settings->dailyMessages()]);
        }
    }

    /**
     * @param  list<array{tool: string, arguments: array<string, string>, result_bytes: int}>  $tools
     */
    private function log(User $user, string $channel, string $outcome, float $startedAt, ?AssistantConversation $conversation = null, ?AssistantTurn $turn = null,
        ?AssistantMessage $answer = null, array $tools = [], ?TextUsage $usage = null, ?string $model = null): AiInteraction
    {
        $team = $user->current_team_id === null ? null : Team::query()->find($user->current_team_id);
        $providerKey = $turn?->providerKey;

        return AiInteraction::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $turn?->conversation->id ?? $conversation?->id,
            'user_message_id' => $turn?->userMessage->id,
            'assistant_message_id' => $answer?->id,
            'channel' => $channel,
            'outcome' => $outcome,
            'intent' => $turn?->route->intent->value,
            'mode' => $turn?->route->mode->value,
            'team_id' => $team?->id,
            'team_role' => $team === null ? null : ($team->isOwnedBy($user) ? 'owner' : $team->roleOf($user)?->value),
            'roles' => $user->getRoleNames()->values()->all(),
            'capabilities' => $turn?->capabilities ?? $this->capabilities($user, $channel),
            'tools' => $tools,
            'provider' => $providerKey,
            'model' => $model ?? ($providerKey === null ? null : (($this->integrations->enabledConfig($providerKey)['model'] ?? null) ?: null)),
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'created_at' => now(),
        ]);
    }
}
