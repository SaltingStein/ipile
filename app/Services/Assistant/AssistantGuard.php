<?php

namespace App\Services\Assistant;

use App\Ai\Agents\AssistantRequestScreen;
use App\Events\Security\SecurityActionTaken;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Ai\AiModels;
use App\Services\Ai\RetriesAiConnections;
use App\Services\Notifications\Notify;
use App\Services\Security\IncidentManager;
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityResponder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Messages\Message;
use Throwable;

/**
 * The site assistant's guard rails, on top of the data block in {@see AssistantDataPolicy}:
 *
 * 1. **Screen** every message before the assistant sees it: fixed rules for clear-cut requests, then an AI check
 *    ({@see AssistantRequestScreen}) for rephrased ones. Asking for the value of a password, one-time code,
 *    recovery code, two-factor secret, API or payment key, for other people's data, or for protected system data
 *    is a violation. How-to questions ("how do I reset my password?") are not.
 * 2. **Enforce**, for everyone including super admins: the request is refused, recorded in Aegis as a high-risk
 *    event with an incident (even while Aegis is off), administrators are emailed, and the person is signed out
 *    everywhere. By default the account is also suspended until an administrator restores it; administrators can
 *    instead choose a two-factor re-check (Admin → Site assistant), in which case trusted devices are forgotten and
 *    a code from the authenticator app is needed to get back in. Accounts without two-factor are always suspended.
 * 3. **Scrub** every answer for anything that looks like a secret, in case one slipped through.
 */
class AssistantGuard
{
    use RetriesAiConnections;

    public const SECURITY_EVENT = 'assistant.sensitive_request';

    /** The AI check must be at least this sure before anyone is signed out. */
    public const CLASSIFIER_CONFIDENCE = 0.85;

    /**
     * Words that ask for a value ("show me…", "what is…").
     */
    private const ASK = '(?:what(?:\'s|’s| is| are| was| were)|tell|show|give|reveal|send|list|print|display|share|read|get|fetch|retrieve|dump|expose|leak|export|decrypt|crack|guess|copy|paste|look ?up|find out|spell out)';

    /**
     * How-to questions, which are always fine to ask.
     */
    private const HOW_TO = '/\b(how (?:do|can|should|would|to)|where (?:do|can|should|is|are)|help me (?:reset|change|set|turn|create|add)|steps? to|instructions? (?:for|to))\b/i';

    /**
     * Add a screening rule of your own, e.g. for your product's sensitive data:
     *
     *     AssistantGuard::rule(AssistantVerdict::OTHER_PEOPLE, '/\bwallet balances?\b/');
     *
     * Each sentence of a message is lower-cased and matched after Ìpìlẹ̀'s own rules, which always run and cannot be
     * removed. A match is a violation in that category (with the usual lock-out) unless the sentence is only a how-to
     * question.
     *
     * @api
     */
    public static function rule(string $category, string $pattern): void
    {
        app(AssistantExtensions::class)->addRule($category, $pattern);
    }

    public function __construct(
        private AssistantSettings $settings,
        private AiModels $models,
        private SecurityResponder $responder,
        private IncidentManager $incidents,
    ) {}

    /**
     * @param  list<Message>  $history  Earlier turns, oldest first.
     */
    public function screen(string $message, array $history = []): AssistantVerdict
    {
        $ruleHit = $this->matchRules($message);

        if ($ruleHit !== null) {
            return AssistantVerdict::violation($ruleHit[0], $ruleHit[1], 'rules');
        }

        $provider = $this->settings->provider() === null ? null : $this->models->provider((string) $this->settings->provider());

        if ($provider === null) {
            return AssistantVerdict::unchecked(AssistantVerdict::NO_MODEL);
        }

        try {
            $transcript = collect($history)->take(-6)
                ->map(fn (Message $turn) => strtoupper($turn->role->value).': '.Str::limit((string) $turn->content, 1000))
                ->push('USER (classify this one): '.Str::limit($message, 2000))
                ->implode("\n\n");
            $result = $this->withConnectionRetries(fn () => (new AssistantRequestScreen)->prompt($transcript, provider: [$provider]));
        } catch (Throwable $exception) {
            Log::warning('The site assistant screen could not run.', ['exception' => $exception]);

            return AssistantVerdict::unchecked(match (true) {
                $exception instanceof InsufficientCreditsException => AssistantVerdict::NO_CREDIT,
                $exception instanceof ProviderConnectionException => AssistantVerdict::UNREACHABLE,
                default => AssistantVerdict::FAILED,
            });
        }

        $category = $result['category'] ?? 'none';
        $confidence = (float) ($result['confidence'] ?? 0);

        if (in_array($category, [AssistantVerdict::CREDENTIALS, AssistantVerdict::OTHER_PEOPLE, AssistantVerdict::SYSTEM_INTERNALS], true)
            && $confidence >= self::CLASSIFIER_CONFIDENCE) {
            return AssistantVerdict::violation($category, Str::limit((string) ($result['reason'] ?? ''), 200), 'ai_check');
        }

        return AssistantVerdict::allowed();
    }

    /**
     * Refuse, record, alert administrators and lock the person out. Returns what was done to the account.
     */
    public function enforce(User $user, AssistantVerdict $verdict, string $message): SecurityAction
    {
        $event = Security::recordFor($user, self::SECURITY_EVENT, [
            'category' => $verdict->category,
            'detected_by' => $verdict->source,
            'reason' => $verdict->reason,
            'request' => Str::limit($message, 200),
        ]);

        // Administrators choose between suspending everyone (the default) and signing out with a two-factor re-check;
        // without two-factor there is nothing to re-check, so those accounts are always suspended.
        $action = $this->settings->lockout() === AssistantSettings::LOCKOUT_SIGN_OUT && $user->hasEnabledTwoFactorAuthentication()
            ? SecurityAction::RevokeSessions
            : SecurityAction::Suspend;
        $person = $user->display_name.' ('.$user->email.')';
        $incident = $event?->fresh()?->incident;

        if ($event !== null) {
            $taken = $this->responder->respond($user, $action, $event, $incident, SecurityActionTaken::SOURCE_ASSISTANT);
            $event->forceFill(['action_taken' => $taken->value])->save();

            if ($incident !== null) {
                $this->incidents->recordAction($incident, $taken);
            }
        } else {
            // Recording failed (it fails open), so lock the account out directly; the email below still goes out.
            $this->lockOut($user, $action);
            $this->responder->announce($user, $action, $action, null, null, SecurityActionTaken::SOURCE_ASSISTANT);
        }

        $this->alertAdministrators($verdict, $person, $action, $incident);

        return $action;
    }

    /**
     * Replace anything that looks like a secret in an answer before anyone sees it.
     */
    public function scrub(string $answer): string
    {
        $patterns = [
            '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}\b/',          // Paystack and similar gateway keys
            '/\bFLW(?:SECK|PUBK)(?:_TEST)?-[A-Za-z0-9-]{8,}\b/',          // Flutterwave keys
            '/\bsk-(?:ant-|or-)?[A-Za-z0-9_-]{16,}\b/',                  // OpenAI, Anthropic and OpenRouter keys
            '/\bAKIA[0-9A-Z]{16}\b/',                                     // AWS access key ids
            '/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/',                      // bcrypt password hashes
            '/\$argon2(?:id|i|d)\$[^\s]+/',                               // argon2 password hashes
            '/\b\d+\|[A-Za-z0-9]{40}\b/',                                 // Sanctum API tokens
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', // JSON web tokens
            '/\b(?:otp|code|passcode|pin)\s*(?:is|:)?\s*\d{4,8}\b/i',     // "your code is 123456"
        ];

        return (string) preg_replace($patterns, '[removed]', $answer);
    }

    /**
     * Each sentence is judged on its own, so a how-to question elsewhere in the message ("How can I…?") does not
     * excuse a request for protected data next to it.
     *
     * @return array{0: string, 1: string}|null [category, reason]
     */
    private function matchRules(string $message): ?array
    {
        foreach (preg_split('/(?<=[.?!;])\s+|\R+/u', Str::lower($message)) ?: [] as $sentence) {
            $hit = trim($sentence) === '' ? null : $this->matchSentence(Str::squish($sentence));

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null [category, reason]
     */
    private function matchSentence(string $text): ?array
    {
        $asks = preg_match('/\b'.self::ASK.'\b/i', $text) === 1;
        $howTo = preg_match(self::HOW_TO, $text) === 1;

        // Attempts to get round the rules or read the database are never how-to questions.
        $internals = [
            '/\b(ignore|disregard|forget|override|bypass)\b.{0,40}\b(instructions?|rules|guard ?rails?|polic(y|ies)|restrictions?|system prompt)\b/',
            '/\b(developer|god|admin|debug|jailbreak|dan) mode\b/',
            '/\bselect\b.{0,80}\bfrom\b\s+\w+/',
            '/\b(integrations|password_reset_tokens|personal_access_tokens|sessions|trusted_devices|users|settings)\s+table\b/',
            '/(\.env\b|\benv file\b|\benvironment variables?\b|\bapp[_ ]key\b|\bdatabase (password|credentials|dump)\b)/',
        ];

        // Asking how to attack the site is probing, however it is phrased ("how can I hack…").
        if (preg_match('/\bhow (?:do|can|could|would|to|should)\s+(?:i|we|you|one|someone)?\s*(?:hack|break into|exploit|crack|bypass|get (?:round|around|past)|take over|brute[- ]?force)\b/', $text) === 1) {
            return [AssistantVerdict::SYSTEM_INTERNALS, 'Matched a rule for asking how to attack the site.'];
        }

        foreach ($internals as $pattern) {
            if (preg_match($pattern, $text) === 1 && ($asks || ! $howTo)) {
                return [AssistantVerdict::SYSTEM_INTERNALS, 'Matched a rule for protected system data or getting round the rules.'];
            }
        }

        if ($howTo || ! $asks) {
            return $this->matchBuilderRules($text, $asks, $howTo);
        }

        $credentials = [
            '/\b(passwords?|passcodes?|passwd|password hash(es)?)\b/',
            '/\b(otps?|one[- ]time (codes?|passwords?|pins?))\b/',
            '/\b(2fa|two[- ]factor|authenticator|verification|login|sign[- ]in|sms|email) (codes?|secrets?|keys?)\b/',
            '/\b(recovery|backup) codes?\b/',
            '/\b(api|secret|private|access|bearer|client|webhook|signing)[ _-]?(keys?|tokens?|secrets?)\b/',
            '/\b(paystack|flutterwave|squad|opay|monnify|moniepoint|zeptomail|smpp|openai|anthropic|gemini|openrouter|payment gateway|gateway)\b.{0,40}\b(keys?|secrets?|credentials?|tokens?|passwords?)\b/',
        ];

        foreach ($credentials as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return [AssistantVerdict::CREDENTIALS, 'Matched a rule for passwords, codes or keys.'];
            }
        }

        $otherPeople = [
            '/\b(other|another|someone else\'?s?|everyone\'?s?|every|all( the)?)\s+(users?|people|members?|customers?|accounts?|admins?|staff)(\'s|’s)?\b.{0,40}\b(emails?|phones?|numbers?|address(es)?|data|details|info(rmation)?|messages|payments?|answers|responses|activity|accounts?)\b/',
            '/\b(emails?|phone( numbers?)?|address(es)?|details|payments?|answers|activity)\s+(of|for|belonging to)\s+(user|member|customer|account|person|admin)s?\b/',
            '/\buser\s*(id\s*)?#?\d+\b/',
        ];

        foreach ($otherPeople as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return [AssistantVerdict::OTHER_PEOPLE, 'Matched a rule for other people’s data.'];
            }
        }

        return $this->matchBuilderRules($text, $asks, $howTo);
    }

    /**
     * Screening rules added with {@see rule()}: checked after Ìpìlẹ̀'s own, with the same how-to exception.
     *
     * @return array{0: string, 1: string}|null [category, reason]
     */
    private function matchBuilderRules(string $text, bool $asks, bool $howTo): ?array
    {
        if ($howTo && ! $asks) {
            return null;
        }

        foreach (app(AssistantExtensions::class)->rules() as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return [$category, 'Matched a screening rule added for this site.'];
                }
            }
        }

        return null;
    }

    /**
     * Everyone who can see threat monitoring, and super admins.
     */
    private function alertAdministrators(AssistantVerdict $verdict, string $person, SecurityAction $action, ?SecurityIncident $incident): void
    {
        $recipients = User::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
                ->orWhereHas('permissions', fn ($permissions) => $permissions->whereIn('name', ['view_security', 'manage_security']))
                ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->whereIn('name', ['view_security', 'manage_security'])))
            ->where('status', '!=', 'suspended')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notify::event('security.admin_alert')
            ->to($recipients)
            ->with([
                'activity' => $verdict->activity(),
                'person' => $person,
                'action' => $action === SecurityAction::Suspend
                    ? 'Account suspended until an administrator restores it from the incident in Admin → Threat monitoring'
                    : 'Signed out everywhere; a code from their authenticator app is needed to sign in again',
                'ip_address' => (string) request()->ip(),
                'time' => now()->toDayDateTimeString(),
                'link' => $incident === null ? route('admin.security.index') : route('admin.security.incidents.show', $incident),
            ])
            ->send();
    }

    private function lockOut(User $user, SecurityAction $action): void
    {
        if ($action === SecurityAction::Suspend) {
            $user->forceFill(['status' => 'suspended'])->save();
        }

        $this->responder->signOutEverywhere($user);
    }
}
