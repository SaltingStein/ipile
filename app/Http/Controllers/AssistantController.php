<?php

namespace App\Http\Controllers;

use App\Ai\Agents\SiteAssistant;
use App\Services\Ai\AiModels;
use App\Services\Ai\RetriesAiConnections;
use App\Services\Assistant\AssistantGuard;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantIntentRouter;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantVerdict;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Messages\Message;
use Throwable;

/**
 * The site assistant at /assistant, for people with a full account while it is switched on (Admin → Site assistant).
 * Every message is screened by AssistantGuard first, then AssistantIntentRouter picks the mode that answers (help, or a
 * staff mode for people allowed to use it). The conversation is kept in the session only.
 */
class AssistantController extends Controller
{
    use RetriesAiConnections;

    public const HISTORY = 'assistant.history';

    public const HISTORY_TURNS = 12;

    /** The intent of the conversation's last message, so a follow-up stays in the same mode. */
    public const INTENT = 'assistant.intent';

    public function show(Request $request, AssistantSettings $settings, AssistantMetrics $metrics, AssistantIntentRouter $router): Response
    {
        abort_unless($settings->enabled(), 404);

        return Inertia::render('Assistant', [
            'history' => array_map(fn (array $turn) => [
                'role' => $turn['role'],
                'content' => $turn['content'],
                'html' => $turn['role'] === 'assistant' ? self::toHtml($turn['content']) : null,
                'mode' => $turn['role'] === 'assistant' ? AssistantIntent::tryFrom((string) ($turn['mode'] ?? ''))?->label() : null,
            ], (array) $request->session()->get(self::HISTORY, [])),
            'figures' => array_values(array_map(fn (array $metric) => $metric['label'], $metrics->availableTo($request->user()))),
            'dailyLimit' => $settings->dailyMessages(),
            'staffModes' => array_map(fn (AssistantIntent $mode) => $mode->label(), $router->staffModesFor($request->user(), AssistantIntentRouter::CHANNEL_PAGE)),
        ]);
    }

    public function send(Request $request, AssistantSettings $settings, AssistantGuard $guard, AiModels $models, AssistantIntentRouter $router): JsonResponse
    {
        abort_unless($settings->enabled(), 404);

        $validated = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $user = $request->user();
        $message = trim($validated['message']);
        $usageKey = 'assistant:messages:'.$user->id.':'.now()->toDateString();

        if ((int) Cache::get($usageKey, 0) >= $settings->dailyMessages()) {
            return response()->json(['message' => __('You have reached today’s limit of :count messages. Try again tomorrow.', ['count' => $settings->dailyMessages()])], 429);
        }

        Cache::add($usageKey, 0, now()->endOfDay());
        Cache::increment($usageKey);

        $history = $this->history($request);
        $verdict = $guard->screen($message, $history);

        if ($verdict->isViolation()) {
            $action = $guard->enforce($user, $verdict, $message);
            $notice = $action === SecurityAction::Suspend
                ? __('The assistant cannot help with passwords, codes, keys or other people’s data. Your account has been suspended for your protection and administrators have been told. Contact an administrator to restore it.')
                : __('The assistant cannot help with passwords, codes, keys or other people’s data. For your protection you have been signed out everywhere and administrators have been told. Sign in again with a code from your authenticator app.');

            // The person is signed out, so the browser leaves the app at once; the sign-in page shows why.
            $request->session()->flash('status', $notice);

            return response()->json(['locked' => true, 'message' => $notice, 'redirect' => route('login')], 423);
        }

        if (! $verdict->isAllowed()) {
            return response()->json(['message' => match ($verdict->reason) {
                AssistantVerdict::NO_MODEL => __('The assistant is not available: its AI model is switched off. An administrator can choose one in Admin → Site assistant.'),
                AssistantVerdict::NO_CREDIT => __('The assistant is not available: its AI service has no credit left. Please tell an administrator.'),
                AssistantVerdict::UNREACHABLE => __('The assistant could not reach its AI service to check your message, so it is not answering. Please try again in a moment.'),
                default => __('The assistant’s AI service could not check your message, so it is not answering. Please try again in a moment.'),
            }], 503);
        }

        $route = $router->route($user, $message, AssistantIntentRouter::CHANNEL_PAGE, AssistantIntent::tryFrom((string) $request->session()->get(self::INTENT)));
        $request->session()->put(self::INTENT, $route->intent->value);
        Security::recordFor($user, 'assistant.message_routed', $route->toContext());
        $provider = $models->provider((string) $settings->provider());

        try {
            $reply = $provider === null ? null : (string) $this->withConnectionRetries(fn () => (new SiteAssistant($user, $history, $route->mode))->prompt($message, provider: [$provider]));
        } catch (Throwable $exception) {
            Log::warning('The site assistant could not answer.', ['exception' => $exception]);
            $reply = null;
        }

        if ($reply === null || trim($reply) === '') {
            return response()->json(['message' => __('The assistant could not answer just now. Please try again.')], 502);
        }

        $reply = $guard->scrub($reply);
        $this->remember($request, $message, $reply, $route->mode);

        return response()->json(['reply' => $reply, 'html' => self::toHtml($reply), 'mode' => $route->mode->value, 'mode_label' => $route->mode->label()]);
    }

    /**
     * The assistant answers in Markdown. Shown as HTML with raw HTML stripped and unsafe links dropped, like
     * help articles, so a reply can never add scripts or tags of its own.
     */
    public static function toHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->session()->forget([self::HISTORY, self::INTENT]);

        return response()->json(['cleared' => true]);
    }

    /**
     * @return list<Message>
     */
    private function history(Request $request): array
    {
        return array_values(array_map(
            fn (array $turn) => new Message($turn['role'], $turn['content']),
            (array) $request->session()->get(self::HISTORY, []),
        ));
    }

    private function remember(Request $request, string $message, string $reply, AssistantIntent $mode): void
    {
        $turns = [
            ...(array) $request->session()->get(self::HISTORY, []),
            ['role' => 'user', 'content' => Str::limit($message, 2000)],
            ['role' => 'assistant', 'content' => Str::limit($reply, 4000), 'mode' => $mode->value],
        ];

        $request->session()->put(self::HISTORY, array_slice($turns, -self::HISTORY_TURNS));
    }
}
