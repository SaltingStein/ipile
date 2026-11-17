<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Ai\RetriesAiConnections;
use App\Services\Assistant\AssistantChat;
use App\Services\Assistant\AssistantChatStopped;
use App\Services\Assistant\AssistantGuard;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantIntentRouter;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The built-in site assistant: its page (/assistant) and the chat bubble on signed-in pages, for people with
 * `use_ai_assistant` while it is switched on (Admin → Site assistant). {@see AssistantChat} screens, routes, stores
 * and logs every message; this controller only answers it, all at once ({@see send()}) or streamed ({@see stream()}).
 * People only ever see and continue their own conversations.
 */
class AssistantController extends Controller
{
    use RetriesAiConnections;

    /** The conversation shown on the assistant page. */
    public const CONVERSATION = 'assistant.conversation';

    public const RECENT_CONVERSATIONS = 20;

    public function show(Request $request, AssistantSettings $settings, AssistantMetrics $metrics, AssistantIntentRouter $router): Response
    {
        abort_unless($settings->enabled(), 404);

        $conversation = $this->ownConversation($request, $request->session()->get(self::CONVERSATION));

        return Inertia::render('Assistant', [
            'history' => $conversation === null ? [] : $this->messages($conversation),
            'figures' => array_values(array_map(fn (array $metric) => $metric['label'], $metrics->availableTo($request->user()))),
            'dailyLimit' => $settings->dailyMessages(),
            'staffModes' => array_map(fn (AssistantIntent $mode) => $mode->label(), $router->staffModesFor($request->user(), AssistantIntentRouter::CHANNEL_PAGE)),
        ]);
    }

    public function send(Request $request, AssistantSettings $settings, AssistantChat $chat): JsonResponse
    {
        abort_unless($settings->enabled(), 404);

        try {
            $turn = $this->begin($request, $chat);
        } catch (AssistantChatStopped $stopped) {
            return $this->stopped($request, $stopped);
        }

        try {
            $response = $this->withConnectionRetries(fn () => $turn->agent()->prompt($turn->message, provider: [$turn->provider]));
            $answer = $chat->complete($turn, (string) $response, $response->usage, $response->meta->model);
        } catch (Throwable $exception) {
            Log::warning('The site assistant could not answer.', ['exception' => $exception]);
            $chat->failed($turn);
            $answer = null;
        }

        if ($answer === null) {
            return response()->json(['message' => __('The assistant could not answer just now. Please try again.'), 'conversation_id' => $turn->conversation->id], 502);
        }

        return response()->json([...$this->reply($turn, $answer), 'reply' => $answer->content]);
    }

    /**
     * The same as {@see send()}, as server-sent events: `start`, then `text` with the answer so far (re-checked by
     * AssistantGuard::scrub() every time and only up to the last complete word, so nothing key-like is shown before
     * it can be removed), then `done` with the final reply as safe HTML, or `failed`.
     */
    public function stream(Request $request, AssistantSettings $settings, AssistantChat $chat, AssistantGuard $guard): JsonResponse|StreamedResponse
    {
        abort_unless($settings->enabled(), 404);

        try {
            $turn = $this->begin($request, $chat);
        } catch (AssistantChatStopped $stopped) {
            return $this->stopped($request, $stopped);
        }

        return response()->stream(function () use ($turn, $chat, $guard): void {
            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $send('start', ['conversation_id' => $turn->conversation->id, 'mode' => $turn->route->mode->value, 'mode_label' => $turn->route->mode->label()]);

            try {
                $stream = $turn->agent()->stream($turn->message, provider: [$turn->provider]);
                $text = '';
                $shown = '';
                $model = null;

                foreach ($stream as $event) {
                    if ($event instanceof StreamStart) {
                        $model = $event->model;
                    }

                    if (! $event instanceof TextDelta) {
                        continue;
                    }

                    $text .= $event->delta;
                    $cut = max(strrpos($text, ' ') ?: 0, strrpos($text, "\n") ?: 0);
                    $safe = $cut === 0 ? '' : $guard->scrub(substr($text, 0, $cut));

                    if ($safe !== $shown) {
                        $send('text', ['text' => $safe]);
                        $shown = $safe;
                    }
                }

                $answer = $chat->complete($turn, $text, $stream->usage, $model);
            } catch (Throwable $exception) {
                Log::warning('The site assistant could not answer.', ['exception' => $exception]);
                $chat->failed($turn);
                $answer = null;
            }

            $answer === null
                ? $send('failed', ['message' => __('The assistant could not answer just now. Please try again.')])
                : $send('done', $this->reply($turn, $answer));
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store, private', 'X-Accel-Buffering' => 'no']);
    }

    /**
     * Start a new conversation on the assistant page.
     */
    public function clear(Request $request): JsonResponse
    {
        $request->session()->forget(self::CONVERSATION);

        return response()->json(['cleared' => true]);
    }

    /**
     * The person's recent conversations, newest first.
     */
    public function conversations(Request $request): JsonResponse
    {
        return response()->json(['conversations' => AssistantConversation::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->limit(self::RECENT_CONVERSATIONS)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (AssistantConversation $conversation) => ['id' => $conversation->id, 'title' => $conversation->title, 'updated_at' => $conversation->updated_at?->toIso8601String()])]);
    }

    public function conversation(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownConversation($request, $conversation) ?? abort(404);

        return response()->json(['id' => $owned->id, 'title' => $owned->title, 'messages' => $this->messages($owned)]);
    }

    public function destroyConversation(Request $request, string $conversation): JsonResponse
    {
        ($this->ownConversation($request, $conversation) ?? abort(404))->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * The assistant answers in Markdown. Shown as HTML with raw HTML stripped and unsafe links dropped, like
     * help articles, so a reply can never add scripts or tags of its own.
     */
    public static function toHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @throws AssistantChatStopped
     */
    private function begin(Request $request, AssistantChat $chat): AssistantTurn
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'channel' => ['sometimes', Rule::in([AssistantIntentRouter::CHANNEL_PAGE, AssistantIntentRouter::CHANNEL_BUBBLE])],
            'conversation_id' => ['sometimes', 'nullable', 'string', 'max:36'],
        ]);
        $channel = $validated['channel'] ?? AssistantIntentRouter::CHANNEL_PAGE;
        if (array_key_exists('conversation_id', $validated) && $validated['conversation_id'] !== null) {
            // Someone else's conversation, or a deleted one, is never continued.
            $conversation = $this->ownConversation($request, $validated['conversation_id']) ?? abort(404);
        } else {
            // The page keeps its conversation in the session; a deleted one simply starts afresh.
            $conversation = $channel === AssistantIntentRouter::CHANNEL_PAGE ? $this->ownConversation($request, $request->session()->get(self::CONVERSATION)) : null;
        }

        $turn = $chat->begin($request->user(), trim($validated['message']), $conversation, $channel);

        if ($channel === AssistantIntentRouter::CHANNEL_PAGE) {
            $request->session()->put(self::CONVERSATION, $turn->conversation->id);
        }

        return $turn;
    }

    private function stopped(Request $request, AssistantChatStopped $stopped): JsonResponse
    {
        if ($stopped->payload['locked'] ?? false) {
            // The person is signed out, so the browser leaves the app at once; the sign-in page shows why.
            $request->session()->flash('status', $stopped->payload['message']);
        }

        return response()->json($stopped->payload, $stopped->status);
    }

    /**
     * @return array{conversation_id: string, html: string, mode: string, mode_label: string}
     */
    private function reply(AssistantTurn $turn, AssistantMessage $answer): array
    {
        return ['conversation_id' => $turn->conversation->id, 'html' => self::toHtml($answer->content), 'mode' => $turn->route->mode->value, 'mode_label' => $turn->route->mode->label()];
    }

    private function ownConversation(Request $request, mixed $id): ?AssistantConversation
    {
        return is_string($id) && Str::isUuid($id)
            ? AssistantConversation::query()->whereKey($id)->where('user_id', $request->user()->id)->first()
            : null;
    }

    /**
     * @return list<array{role: string, content: string, html: string|null, mode: string|null}>
     */
    private function messages(AssistantConversation $conversation): array
    {
        return $conversation->messages()->get()->map(fn (AssistantMessage $message) => [
            'role' => $message->role,
            'content' => $message->content,
            'html' => $message->role === AssistantMessage::ROLE_ASSISTANT ? self::toHtml($message->content) : null,
            'mode' => $message->role === AssistantMessage::ROLE_ASSISTANT ? AssistantIntent::tryFrom((string) $message->mode)?->label() : null,
        ])->values()->all();
    }
}
