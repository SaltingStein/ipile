<?php

namespace App\Services\Assistant;

use App\Mcp\Servers\WidgetDataServer;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Decides which mode of the site assistant answers a message. Deterministic, with no AI involved: the same message
 * from the same person always lands in the same mode, so routing can be tested and audited.
 *
 * 1. **Intent:** each intent has fixed keyword rules (plus any a builder adds with {@see rule()}); the intent with the
 *    most matching rules wins, ties going to {@see AssistantIntent::CoreGuidance}, then Analytics. A message that
 *    matches nothing is a follow-up and keeps the conversation's previous intent, or is end-user support.
 * 2. **Mode:** a staff intent is answered in its staff mode only when an administrator switched that mode on, the
 *    person has its permission and a full account, and the channel allows staff modes. Otherwise the product-facing
 *    assistant answers. The built-in assistant (its page and chat bubble) is such a channel; the third-party widget's data connection
 *    ({@see WidgetDataServer}) never is.
 *
 * Routing never loosens screening: every message passes {@see AssistantGuard} before it is routed.
 */
class AssistantIntentRouter
{
    /** The built-in assistant page (/assistant). */
    public const CHANNEL_PAGE = 'page';

    /** The built-in assistant's chat bubble on signed-in pages (CORE-911). */
    public const CHANNEL_BUBBLE = 'bubble';

    /** The third-party widget's data connection. Product-facing only. */
    public const CHANNEL_WIDGET = 'widget';

    /** Channels where staff modes may answer. */
    public const STAFF_CHANNELS = [self::CHANNEL_PAGE, self::CHANNEL_BUBBLE];

    /**
     * Intent => rules matched against the lower-cased message. Order is the tie-break.
     *
     * @var array<string, list<string>>
     */
    public const RULES = [
        'core_guidance' => [
            '/\b(ipile|core-?ng|boilerplate|codebase|architecture)\b/',
            '/ìpìlẹ̀/u',
            '/\badmin (area|page|pages|menu|panel|dashboard|settings)\b/',
            '/\b(integrations?|aegis|threat monitoring|security (centre|center)|incidents?)\b/',
            '/\b(permissions?|roles? (and|&) permissions)\b/',
            '/\b(configure|configuration|set ?up|settings?)\b.{0,40}\b(site|platform|assistant|payments?|sms|emails?|sign-in|oauth|branding|theme|widget)\b/',
            '/\b(developers?|code|routes?|migrations?|deploy(ment)?|service providers?|artisan|skills?|extend|extensions?|hooks?)\b/',
            '/\b(who (owns|is responsible for|maintains)|ownership|runbook|run(ning)? the site)\b/',
        ],
        'analytics' => [
            '/\bhow many\b/',
            '/\b(number|count|total|sum|average|percentage|share) of\b/',
            '/\b(figures?|metrics?|stats|statistics|analytics|kpis?|trends?|growth|revenue|conversions?|sign-?ups)\b/',
            '/\b(compare|comparison|versus|week on week|month on month|last (week|month|quarter|year|\d+ days))\b/',
        ],
        'end_user_support' => [
            '/\bmy (account|profile|password|email|phone|teams?|invitations?|devices?|sessions?|data)\b/',
            '/\b(reset|change|forgot|forgotten)\b.{0,20}\bpassword\b/',
            '/\b(two-factor|2fa|authenticator)\b/',
        ],
    ];

    public function __construct(private AssistantSettings $settings, private AssistantExtensions $extensions) {}

    /**
     * Add a keyword rule for an intent, e.g. `AssistantIntentRouter::rule(AssistantIntent::Analytics, '/\bpayouts?\b/')`.
     * Rules only add weight; they cannot change who may use a staff mode. Call it in a service provider's `boot()`.
     *
     * @api
     */
    public static function rule(AssistantIntent $intent, string $pattern): void
    {
        app(AssistantExtensions::class)->addIntentRule($intent->value, $pattern);
    }

    public function classify(string $message, ?AssistantIntent $previous = null): AssistantIntent
    {
        $text = Str::squish(Str::lower($message));
        $scores = [];

        foreach (AssistantIntent::cases() as $intent) {
            $rules = [...self::RULES[$intent->value], ...($this->extensions->intentRules()[$intent->value] ?? [])];
            $scores[$intent->value] = count(array_filter($rules, fn (string $rule) => preg_match($rule, $text) === 1));
        }

        $best = max($scores);

        if ($best === 0) {
            return $previous ?? AssistantIntent::EndUserSupport;
        }

        // array_search returns the first key with the best score, so RULES order breaks ties.
        return AssistantIntent::from((string) array_search($best, $scores, true));
    }

    public function route(User $user, string $message, string $channel, ?AssistantIntent $previous = null): AssistantRoute
    {
        $intent = $this->classify($message, $previous);

        return new AssistantRoute($intent, $this->allows($user, $intent, $channel) ? $intent : AssistantIntent::EndUserSupport, $channel);
    }

    public function allows(User $user, AssistantIntent $mode, string $channel): bool
    {
        if (! in_array($channel, [self::CHANNEL_PAGE, self::CHANNEL_BUBBLE, self::CHANNEL_WIDGET], true)) {
            throw new InvalidArgumentException("Unknown assistant channel [{$channel}].");
        }

        if (! $mode->isInternal()) {
            return true;
        }

        return in_array($channel, self::STAFF_CHANNELS, true)
            && $this->settings->staffModeEnabled($mode)
            && ! $user->is_guest
            && ! $user->isSuspended()
            && $user->can((string) $mode->permission());
    }

    /**
     * The staff modes this person can use on this channel.
     *
     * @return list<AssistantIntent>
     */
    public function staffModesFor(User $user, string $channel): array
    {
        return array_values(array_filter(AssistantIntent::internal(), fn (AssistantIntent $mode) => $this->allows($user, $mode, $channel)));
    }
}
