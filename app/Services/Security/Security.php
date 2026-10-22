<?php

namespace App\Services\Security;

use App\Events\Security\SecurityActionTaken;
use App\Events\Security\SecurityIncidentOpened;
use App\Events\Security\SecurityRiskAssessed;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\Signals\SecuritySignal;
use App\Services\Security\Signals\SecuritySignals;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

/**
 * The one way to feed Aegis, and the extension points for adopters:
 *
 *     // 1. Describe a new event (in a service provider's boot()).
 *     Security::define(new SecurityEventDefinition('orders.refunded', 'orders', 'Order refunded',
 *         'A refund was issued.', sensitivity: 0.8));
 *
 *     // 2. Record it where it happens: about the signed-in user, or about a given account.
 *     Security::record('orders.refunded', ['amount_kobo' => 500000]);
 *     Security::recordFor($order->user, 'orders.refunded');
 *
 *     // …or on a route:      ->middleware('security.track:orders.refunded')
 *     // …or from your event:  Security::listen(OrderRefunded::class, 'orders.refunded', fn ($event) => $event->order->user);
 *
 *     // 3. Add your own evidence.
 *     Security::signal(UnusualRefundAmountSignal::class);
 *
 *     // 4. Ask before a risky action, or demand a fresh authenticator code.
 *     Security::check('payouts.requested', context: ['amount_kobo' => $amount])->authorize();
 *     Security::requireFreshTwoFactor();        // …or on a route: ->middleware('security.confirm:2fa,10')
 *
 *     // 5. Add your own consequences to Aegis responses.
 *     Security::onAction(SecurityAction::Suspend, FreezeWallet::class);
 *
 * React to results by listening for {@see SecurityRiskAssessed}, {@see SecurityIncidentOpened},
 * {@see SecurityActionTaken} and the other events in `App\Events\Security`; each has a stable `toExport()` payload.
 *
 * Nothing here is switched on by default: every hook is inert until you use it.
 *
 * @api
 */
final class Security
{
    /**
     * Record that something security-relevant happened to the signed-in user (or to nobody when signed out).
     * Returns null when Aegis is off, the category is out of scope, or recording failed (monitoring never breaks
     * the request).
     *
     * @param  array<string, scalar|null>  $context  Small, non-secret values. Never pass passwords, tokens or card numbers.
     */
    public static function record(string $type, array $context = []): ?SecurityEvent
    {
        return self::recordFor(Auth::user() instanceof User ? Auth::user() : null, $type, $context);
    }

    /**
     * Record an event about a specific account, e.g. the account a failed sign-in targeted. Pass null when no
     * account is involved (a sign-in attempt for an unknown email).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function recordFor(?User $user, string $type, array $context = []): ?SecurityEvent
    {
        return app(SecurityMonitor::class)->record($type, $user, $context);
    }

    /**
     * Add (or replace) an event type.
     */
    public static function define(SecurityEventDefinition $definition): void
    {
        app(SecurityEventCatalog::class)->register($definition);
    }

    /**
     * Give a custom category a readable name on the security centre pages.
     */
    public static function category(string $key, string $label, string $description = ''): void
    {
        app(SecurityEventCatalog::class)->registerCategory($key, $label, $description);
    }

    /**
     * Record a security event whenever one of your application events is dispatched.
     *
     * @param  class-string  $eventClass
     * @param  (Closure(object): ?User)|null  $user  Who the event is about; defaults to the signed-in user.
     * @param  (Closure(object): array<string, scalar|null>)|null  $context
     */
    public static function listen(string $eventClass, string $type, ?Closure $user = null, ?Closure $context = null): void
    {
        Event::listen($eventClass, function (object $event) use ($type, $user, $context): void {
            $values = $context === null ? [] : $context($event);

            if ($user === null) {
                self::record($type, $values);
            } else {
                self::recordFor($user($event), $type, $values);
            }
        });
    }

    /**
     * Record the event (logged and scored as usual) and decide whether the action it describes should go ahead.
     * Fails open: when Aegis is off, the category is switched off or scoring fails, the action is allowed, except for
     * events with a forced score. The decision is advisory until you call `->authorize()` on it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function check(string $type, ?User $user = null, array $context = []): SecurityDecision
    {
        return app(SecurityMonitor::class)->check($type, $user ?? (Auth::user() instanceof User ? Auth::user() : null), $context);
    }

    /**
     * The decision {@see check()} would give, without recording anything or changing the account's posture. For
     * hints such as "this will need your authenticator app" before the person submits.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function preview(string $type, ?User $user = null, array $context = []): SecurityDecision
    {
        return app(SecurityMonitor::class)->preview($type, $user ?? (Auth::user() instanceof User ? Auth::user() : null), $context);
    }

    /**
     * Continue only if the signed-in person entered a code from their authenticator app within the last $minutes in
     * this browser; otherwise send them to confirm (and back). Accounts without two-factor set one up first, or
     * confirm their password when $fallback is `password`.
     *
     * @param  'setup'|'password'  $fallback
     *
     * @throws SecurityChallengeRequired
     */
    public static function requireFreshTwoFactor(int $minutes = FreshTwoFactor::DEFAULT_MINUTES, string $fallback = FreshTwoFactor::FALLBACK_SETUP): void
    {
        app(FreshTwoFactor::class)->ensure(Auth::user() instanceof User ? Auth::user() : null, $minutes, $fallback);
    }

    /**
     * Run your own code after Aegis (or the site assistant's guard rails) takes a response on an account, e.g. freeze
     * a wallet when an account is suspended. Handlers receive {@see SecurityActionTaken}; one that throws is reported
     * and never stops the others or the response itself.
     *
     * @param  callable(SecurityActionTaken): mixed|class-string  $handler
     */
    public static function onAction(SecurityAction $action, callable|string $handler): void
    {
        app(SecurityResponseHooks::class)->on($action, $handler);
    }

    /**
     * In tests: replace Aegis with an in-memory fake that keeps what was recorded and checked, allows every check
     * unless you force a decision, and offers assertions (`assertRecorded()`, `assertChecked()`…).
     */
    public static function fake(): SecurityFake
    {
        $fake = new SecurityFake(app(SecurityEventCatalog::class));
        app()->instance(SecurityMonitor::class, $fake);

        return $fake;
    }

    /**
     * Add a `user_reference` (a keyed hash of the account id) to every event's `toExport()` payload, so a SIEM can
     * link activity by account without learning who it is. Off by default.
     */
    public static function exportUserReferences(?string $key = null): void
    {
        app(SecurityExportPayload::class)->includeUserReferences($key);
    }

    /**
     * Score events with an extra signal: your own class, or a configured building block that applies only to the
     * event types you name, e.g. `Security::signal(VelocitySignal::for('payouts.requested', max: 5, perMinutes: 60))`.
     *
     * @param  class-string<SecuritySignal>|SecuritySignal  $signal
     */
    public static function signal(string|SecuritySignal $signal): void
    {
        app(SecuritySignals::class)->register($signal);
    }
}
