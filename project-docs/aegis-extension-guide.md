# Extending Aegis

Aegis is Ìpìlẹ̀'s security monitoring engine (Admin → Security → Threat monitoring). It records security events, scores them, opens incidents and, in **Monitor and respond** mode, responds: alert the owner → ask for the password again → sign out everywhere → suspend.

This guide is for developers building a product on Ìpìlẹ̀. It shows how to make Aegis watch and protect **your** features. Ìpìlẹ̀ provides the capability; you decide the scope. **Nothing here is switched on by default**: every hook does nothing until you use it, so an app that uses none of them behaves exactly as before.

How Aegis works inside (signals, the two Markov models, incidents) is in [dev-notes.md → Aegis security monitoring](dev-notes.md#aegis-security-monitoring).

| I want to… | Use |
|---|---|
| Watch a risky action in my feature | [`Security::define()` + `Security::record()`](#1-describe-and-record-your-events) |
| Stop or challenge an action before it happens | [`Security::check()` → `SecurityDecision`](#2-ask-before-a-risky-action) |
| Show "this will need your authenticator app" in advance | [`Security::preview()`](#preview-without-recording) |
| Require a fresh authenticator code on a route | [`security.confirm` middleware](#3-require-a-fresh-two-factor-code) |
| Always respond at least a certain way to one event | [`minimumAction`](#minimum-action-per-event) |
| Freeze a wallet when Aegis suspends an account | [`Security::onAction()`](#4-add-your-own-consequences) |
| Send security activity to a SIEM, Slack or a webhook | [Lifecycle events + `toExport()`](#5-stream-security-activity) |
| Catch "too many, too fast", "unusually large" or "at an odd hour" | [Configurable signals](#6-switch-on-ready-made-signals) |
| Keep the site assistant away from my tables, or give it my own figures and tools | [Assistant extension points](#7-extend-the-site-assistant) |
| Test all of it | [`Security::fake()` and `make:security-signal`](#8-test-your-extensions) |
| Start from a worked setup | [Recipes](#recipes) |

---

## 1. Describe and record your events

Describe each kind of event once, in a service provider's `boot()`, then record it where it happens.

```php
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventDefinition;

// AppServiceProvider::boot()
Security::category('payouts', 'Payouts', 'Money leaving the platform.');
Security::define(new SecurityEventDefinition(
    'payouts.requested', 'payouts', 'Payout requested',
    'A customer asked to withdraw money to a bank account.',
    sensitivity: 0.9,
));
```

```php
Security::record('payouts.requested', ['amount_kobo' => $amount]);           // the signed-in user
Security::recordFor($payout->user, 'payouts.requested');                     // a given account
Route::post('/payouts', ...)->middleware('security.track:payouts.requested'); // on a route (successful requests only)
```

Context values must be small scalars (amounts, ids, counts). Never pass passwords, tokens or card numbers.

## 2. Ask before a risky action

`Security::check()` records the event as usual (so it is logged and scored) and returns a `SecurityDecision`:

```php
public function store(PayoutRequest $request): RedirectResponse
{
    Security::check('payouts.requested', context: ['amount_kobo' => $request->integer('amount_kobo')])
        ->authorize();

    // Only reached when Aegis allows it.
    $this->payouts->send(...);

    return back()->with('status', __('Payout on its way.'));
}
```

| Aegis recommends | Decision | `->authorize()` |
|---|---|---|
| Nothing, or an alert | `allowed()` | continues |
| Ask for the password again (or the event's `minimumAction` is StepUp) | `requiresFreshTwoFactor()` | browsers go to **Confirm it's you** and come back; JSON callers get `423` with `confirm_url` |
| Sign out everywhere or suspend | `denied()` | browsers go back with a `security` error; JSON callers get `403` |

- **Advisory until you enforce it.** Without `->authorize()` nothing is blocked; read `$decision->outcome`, `score`, `action` and `reason` (plain words you can show) and decide yourself.
- **Fail-open.** When Aegis is off, the event's category is switched off or scoring fails, the action is allowed, except for events with a `forcedScore`, which are decided from that score.
- **No double prompt.** Someone who entered a fresh authenticator code in this browser in the last 10 minutes is allowed instead of challenged.
- **Monitor mode** gives the same decisions; only Aegis's own responses (alerts, sign-outs) wait for **Monitor and respond**.

### Preview without recording

`Security::preview()` returns the decision `check()` would give now, without recording the event, moving the account's posture, opening an incident or responding. Use it for hints before someone submits:

```php
'needsAuthenticator' => Security::preview('payouts.requested', context: ['amount_kobo' => $amount])->requiresFreshTwoFactor(),
```

## 3. Require a fresh two-factor code

On routes that move money or change sensitive details, a stolen session alone should not be enough:

```php
Route::post('/payouts', [PayoutController::class, 'store'])->middleware('security.confirm:2fa,5');
Route::put('/bank-details', [BankDetailsController::class, 'update'])->middleware('security.confirm:2fa,10,password');
```

`security.confirm:2fa,{minutes},{fallback}` lets the request through only if this browser entered a code from the authenticator app (or a recovery code) within `{minutes}` (default 10). Otherwise the person is sent to **Confirm it's you** (`/security/confirm`) and brought back afterwards; JSON callers get `423` with `confirm_url`.

| Account | `setup` (default) | `password` |
|---|---|---|
| Has two-factor | asked for a code | asked for a code |
| No two-factor | sent to set up an authenticator app first | asked for the password |

Inside a controller or action: `Security::requireFreshTwoFactor(5)` (same rules).

- Confirmations live in the session: a new browser always asks again.
- A fresh code also counts as a password confirmation, so it clears an Aegis "ask for the password again".
- Wrong codes are recorded as `auth.two_factor_failed`; successes as `account.two_factor_confirmed`. Attempts are rate-limited.
- End users read [Why am I asked for my authenticator code again?](/help/confirm-with-authenticator).

## Minimum action per event

Give your most sensitive events a floor, whatever their score:

```php
Security::define(new SecurityEventDefinition('payouts.requested', 'payouts', 'Payout requested', '...',
    sensitivity: 0.9,
    minimumAction: SecurityAction::StepUp,   // always at least a fresh code
));
```

- In **Monitor and respond** mode it is the minimum response Aegis takes.
- In **Monitor** mode it only shapes `check()` and `preview()` decisions; no response is taken.
- Failed attempts by unverified actors (`actorIsVerified: false`) never get more than an alert to the owner, whatever the minimum, so nobody can lock a victim out by failing on purpose.

## 4. Add your own consequences

Run your code after Aegis (or the site assistant's guard rails) responds to an account:

```php
use App\Events\Security\SecurityActionTaken;

Security::onAction(SecurityAction::Suspend, FreezeWallet::class);
Security::onAction(SecurityAction::RevokeSessions, fn (SecurityActionTaken $taken) => Payouts::holdFor($taken->user));
```

```php
class FreezeWallet
{
    public function handle(SecurityActionTaken $taken): void
    {
        $taken->user->wallet?->freeze(reason: 'security', incidentId: $taken->incident?->id);
    }
}
```

- Handlers run in the order you add them, right after the response, for the response **actually taken** (a password re-check that cannot apply becomes an alert, and runs alert handlers).
- `$taken` has `user`, `action`, `requested`, `event`, `incident` and `source` (`aegis` or `assistant`).
- A handler that throws is reported and skipped; the others and the response itself always complete.
- Undo your consequence when an administrator lifts a suspension: listen for `SecurityAccountRestored`.
- Keep handlers quick. Dispatch a queued job for anything slow.

## 5. Stream security activity

Every event below has a stable `toExport()` payload, so a listener can forward it as it is:

| Event | When |
|---|---|
| `SecurityEventRecorded` | Every saved security event (before incidents and responses) |
| `SecurityRiskAssessed` | After scoring, incident and response |
| `SecurityIncidentOpened` | A new incident |
| `SecurityActionTaken` | A response on an account |
| `SecurityIncidentResolved` | An administrator resolved an incident |
| `SecurityAccountRestored` | A suspension was lifted (incident page or `ipile:restore-user`) |

```php
use App\Events\Security\SecurityRiskAssessed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;

class ForwardToSiem implements ShouldQueue
{
    public function handle(SecurityRiskAssessed $event): void
    {
        Http::withToken($this->token())->post($this->url(), $event->toExport());
    }
}
```

Read the SIEM address and token from an integration (`IntegrationCatalog`), not `.env`.

**Payload, schema 1:**

```json
{
  "schema": 1,
  "type": "security.risk_assessed",
  "occurred_at": "2026-10-21T14:03:11+00:00",
  "user_reference": null,
  "decision_score": 82,
  "event": { "id": 512, "type": "payouts.requested", "category": "payouts", "risk_score": 82, "posture_score": 40,
             "recommended_action": "step_up", "action_taken": null, "signals": [{ "key": "new_device", "score": 0.9 }],
             "incident_id": 37, "occurred_at": "2026-10-21T14:03:11+00:00" },
  "incident": { "id": 37, "status": "open", "risk_score": 82, "risk_level": "high", "recommended_action": "step_up",
                "action_taken": null, "event_count": 3, "resolution": null, "first_event_at": "…", "last_event_at": "…", "resolved_at": null }
}
```

- **No personal data:** no names, emails, IP addresses, user agents, signal details or event context. If your destination needs them, read them from the event's models (`$event->event->user`) in your own listener.
- **Correlate without identifying:** `Security::exportUserReferences()` adds `user_reference`, an HMAC of the account id (keyed with the app key, or a key you pass). The same account always gets the same reference for one key.
- The new events (`SecurityEventRecorded`, `SecurityActionTaken`, `SecurityIncidentResolved`, `SecurityAccountRestored`) dispatch **after the database commit**, so queued listeners see saved data.
- A listener that throws is reported; it never stops incidents or responses.

---

## 6. Switch on ready-made signals

Signals are the evidence each event is scored with. Ìpìlẹ̀'s own (new device, new network, bursts, recent failures, unusual sequences) apply to every event. These building blocks apply **only to the event types you name**, and none is on until you register it:

```php
use App\Services\Security\Signals\ThresholdSignal;
use App\Services\Security\Signals\TimeWindowSignal;
use App\Services\Security\Signals\VelocitySignal;

// AppServiceProvider::boot()
Security::signal(VelocitySignal::for('payouts.requested', max: 5, perMinutes: 60));
Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', above: 50_000_000));
Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', timesUsual: 5));
Security::signal(TimeWindowSignal::for('payouts.requested', outside: '06:00-22:00', timezone: 'Africa/Lagos'));
```

| Signal | Scores when | Strength |
|---|---|---|
| `VelocitySignal::for($types, max:, perMinutes:)` | The account (or IP address, when nobody is signed in) did more than `max` of these events in the window | 0.5 just over the limit, 1.0 at twice it |
| `ThresholdSignal::on($types, $contextKey, above:)` | The number under `$contextKey` in the event's context is above the limit | 0.6 just over, 1.0 at twice it |
| `ThresholdSignal::on(..., timesUsual:)` | It is more than `timesUsual` × the median of the account's last 50 values (needs at least 5) | 0.6 just over, 1.0 at twice it |
| `TimeWindowSignal::for($types, outside:, timezone:)` | The event happened outside the expected hours (a window may cross midnight, e.g. `22:00-06:00`) | `score:` (default 1.0) |

- Every block takes an optional `weight:` (0–1) for how much it counts towards the event's score.
- Registering the same block for the same types again replaces the earlier one.
- They stay cheap: at most one indexed query each, and no calls to other services.
- Your own signal: a class implementing `SecuritySignal` (see [dev notes](dev-notes.md#aegis-security-monitoring)), registered with `Security::signal(MySignal::class)`.

## 7. Extend the site assistant

The site assistant's limits are code, not settings. You can add to them, never take any away:

```php
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantGuard;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantIntentRouter;
use App\Services\Assistant\AssistantKnowledge;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantTools;
use App\Services\Assistant\AssistantVerdict;

// AppServiceProvider::boot()
AssistantDataPolicy::block('wallets');                    // the whole table
AssistantDataPolicy::block('bank_accounts', ['account_number', 'bvn']);   // only these columns

AssistantMetrics::define('payouts.last_30_days', 'Payouts', 'Number and total of payouts in the last 30 days.',
    'view_billing', fn () => [
        'payouts' => Payout::query()->where('created_at', '>=', now()->subDays(30))->count(),
        'total_kobo' => (int) Payout::query()->where('created_at', '>=', now()->subDays(30))->sum('amount_kobo'),
    ]);

AssistantTools::register(MyWallet::class);
AssistantTools::register(PayoutTrends::class, [AssistantIntent::Analytics]);   // staff analytics mode only

AssistantGuard::rule(AssistantVerdict::OTHER_PEOPLE, '/\bwallet balances?\b/');

AssistantIntentRouter::rule(AssistantIntent::Analytics, '/\bpayouts?\b/');      // route payout questions to analytics
AssistantKnowledge::add('docs/runbook.md');                                     // extra guide for the guidance mode
```

- **Blocked data:** while assistant tools run, every query touching a blocked table is refused before it is sent. A table with protected columns can then be read only with named columns, never `select *`.
- **Figures:** yours appear in **Admin → Site assistant → Business figures** for administrators to allow. A person gets one only when it is allowed **and** they have its permission. Return counts and totals, never personal details. Ìpìlẹ̀'s own figure keys cannot be replaced.
- **Tools:** extend `App\Ai\Tools\AssistantTool` and implement `description()`, `schema()` and `read()`. The tool is built for each conversation with the person as `$this->user`; check your own permissions in `read()`. `handle()` is final, so every tool runs under the data block.
- **Screening rules:** each sentence of a message is lower-cased and matched after Ìpìlẹ̀'s rules. A match is a violation in that category, with the same refusal, incident, admin email and lock-out, unless the sentence is only a how-to question.
- **Staff modes:** the assistant answers each message as **Help**, **Site guidance** or **Analytics** (`AssistantIntentRouter`). A tool registered with modes is offered only in those modes, never to the product-facing assistant or the widget; use it for staff-only insights. Intent rules only add routing weight: who may use a staff mode is still decided by its permission and the admin switch. Guide files you add are read only in the guidance mode, so keep secrets out of them.
- **Third-party widget:** when an admin turns on the widget's **Data access**, its service calls tools through `/mcp/widgets`. Your tools join it only with `WidgetDataTools::register(MyWallet::class)`. Offer only what may leave the site: no names, emails or other people's records. Each call is recorded as `mcp.tool_called`. See [widget-data-connection.md](widget-data-connection.md).

## 8. Test your extensions

Every hook you add needs a feature test. `Security::fake()` replaces Aegis with an in-memory stand-in: nothing is scored or saved, every check is allowed unless you force a decision, and unknown event types still throw.

```php
use App\Services\Security\Security;
use App\Services\Security\SecurityDecision;

public function test_large_payouts_ask_for_a_fresh_code(): void
{
    $security = Security::fake()->decide('payouts.requested', SecurityDecision::challenge());
    $user = User::factory()->withTwoFactor()->create();

    $response = $this->actingAs($user)->post('/payouts', ['amount_kobo' => 500_000]);

    $response->assertRedirect(route('security.confirm'));
    $security->assertChecked('payouts.requested', fn (?User $user, array $context) => $context['amount_kobo'] === 500_000);
}
```

| Assertion | Passes when |
|---|---|
| `assertRecorded($type, $callback?)` | The event was recorded (also by `check()`, `security.track` and `Security::listen()`) |
| `assertNotRecorded($type)` / `assertNothingRecorded()` | It was not / nothing was |
| `assertChecked($type, $callback?)` / `assertNotChecked($type)` | `Security::check()` was / was not called for it |
| `assertPreviewed($type, $callback?)` | `Security::preview()` was called for it |

Callbacks receive the user (or null) and the context array. Leave the fake out when you want to test real scoring, incidents or responses; your signal tests usually do.

For a signal of your own: `php artisan make:security-signal UnusualRefund` creates `app/Security/Signals/UnusualRefundSignal.php` and `tests/Feature/Security/Signals/UnusualRefundSignalTest.php`. It never overwrites an existing class (`--force` to allow, `--no-test` to skip the test). Register it with `Security::signal(UnusualRefundSignal::class)`.

AI coding agents load the `ipile-security` skill for all of this.

---

## Recipes

Documentation only: Ìpìlẹ̀ registers none of this. Copy what fits your product into a service provider's `boot()`, then add the feature tests.

### Fintech: protect money movement

```php
Security::category('payouts', 'Payouts', 'Money leaving the platform.');
Security::define(new SecurityEventDefinition('payouts.requested', 'payouts', 'Payout requested',
    'A customer asked to withdraw money.', sensitivity: 1.0, minimumAction: SecurityAction::StepUp));
Security::define(new SecurityEventDefinition('payouts.bank_details_changed', 'payouts', 'Bank details changed',
    'The account the money goes to was changed.', sensitivity: 1.0));

Security::signal(VelocitySignal::for('payouts.requested', max: 3, perMinutes: 60));
Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', timesUsual: 5));
Security::signal(TimeWindowSignal::for(['payouts.requested', 'payouts.bank_details_changed'], outside: '06:00-23:00', timezone: 'Africa/Lagos'));

Security::onAction(SecurityAction::RevokeSessions, HoldPendingPayouts::class);
Security::onAction(SecurityAction::Suspend, FreezeWallet::class);
Event::listen(SecurityAccountRestored::class, UnfreezeWallet::class);

AssistantDataPolicy::block('bank_accounts', ['account_number', 'bvn']);
AssistantDataPolicy::block('wallet_transactions');
```

```php
Route::put('/bank-details', [BankDetailsController::class, 'update'])
    ->middleware(['security.confirm:2fa,5', 'security.track:payouts.bank_details_changed']);

// PayoutController::store()
Security::check('payouts.requested', context: ['amount_kobo' => $amount])->authorize();
```

Turn on **Monitor and respond** in Admin → Security → Monitoring settings once you have watched the scores for a while in Monitor mode.

### Marketplace: payout details and refund bursts

```php
Security::define(new SecurityEventDefinition('sellers.payout_details_changed', 'sellers', 'Seller payout details changed',
    'A seller changed where their earnings are paid.', sensitivity: 1.0, minimumAction: SecurityAction::StepUp));
Security::define(new SecurityEventDefinition('orders.refunded', 'orders', 'Order refunded',
    'A seller or support agent issued a refund.', sensitivity: 0.7));

Security::signal(VelocitySignal::for('orders.refunded', max: 10, perMinutes: 60));
Security::signal(ThresholdSignal::on('orders.refunded', 'amount_kobo', timesUsual: 4));
Security::onAction(SecurityAction::Suspend, PauseSellerListings::class);
AssistantGuard::rule(AssistantVerdict::OTHER_PEOPLE, '/\b(buyer|seller)s?\b.{0,30}\b(address(es)?|phone( numbers?)?)\b/');
```

### Education and records: grade changes and bulk exports

```php
Security::define(new SecurityEventDefinition('records.grade_changed', 'records', 'Grade changed',
    'A recorded grade was edited after publishing.', sensitivity: 0.8));
Security::define(new SecurityEventDefinition('records.exported', 'records', 'Records exported',
    'Student records were downloaded in bulk.', sensitivity: 1.0, minimumAction: SecurityAction::StepUp));

Security::signal(VelocitySignal::for('records.grade_changed', max: 20, perMinutes: 30));
Security::signal(TimeWindowSignal::for('records.exported', outside: '07:00-19:00', timezone: 'Africa/Lagos'));
AssistantDataPolicy::block('student_records');
```

```php
Route::post('/records/export', ExportRecordsController::class)->middleware('security.confirm:2fa,10');
```

---

## Stability promise

Classes and methods marked `@api` (the `Security` facade, `SecurityEventDefinition`, `SecurityDecision`, `SecurityOutcome`, the exceptions `SecurityChallengeRequired` and `SecurityActionDenied`, the security events, `SecurityExportPayload`, the signal building blocks, `AssistantDataPolicy::block()`, `AssistantMetrics::define()`, `AssistantTools`, `AssistantTool`, `AssistantGuard::rule()` and `SecurityFake`) do not change in a breaking way outside an Ìpìlẹ̀ major version, and every change is noted in the changelog. Export payloads change only with a new `schema` number. Everything else is internal and may change.
