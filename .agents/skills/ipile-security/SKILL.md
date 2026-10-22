---
name: ipile-security
description: "Extends Aegis, Ìpìlẹ̀'s security monitoring, for a product's own features: risky-action events, pre-action checks, fresh two-factor on routes, consequences when accounts are locked out, signals, streaming security activity, and the site assistant's limits. Use when the developer wants to protect payouts, money movement, bank details, exports, role changes or other sensitive actions; detect fraud, abuse or unusual activity; require 2FA again; freeze or hold something when an account is suspended; send security events to a SIEM or webhook; or keep the AI assistant away from their tables."
---

# Ìpìlẹ̀ security (Aegis extensions)

Aegis records security events, scores them, opens incidents and, in **Monitor and respond** mode, responds (alert → password re-check → sign out everywhere → suspend). Products extend it through published hooks. **Ìpìlẹ̀ provides capability; the product decides scope.** Never add product rules to Ìpìlẹ̀'s own files (`app/Services/Security/*`, `AssistantDataPolicy` constants, `SecurityEventCatalog::builtIn()`); register everything from a service provider's `boot()`.

Full examples: `project-docs/aegis-extension-guide.md`. Internals: `project-docs/dev-notes.md` → Aegis.

## 1. Describe and record the event

```php
use App\Services\Security\Security;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventDefinition;

Security::category('payouts', 'Payouts', 'Money leaving the platform.');
Security::define(new SecurityEventDefinition('payouts.requested', 'payouts', 'Payout requested',
    'A customer asked to withdraw money.', sensitivity: 0.9, minimumAction: SecurityAction::StepUp));
```

- Keys look like `area.what_happened`; unknown keys throw.
- `sensitivity` 0–1 (damage in the wrong hands). `isFailure` for failed attempts. `actorIsVerified: false` when anyone can cause it (responses then stop at an alert). `forcedScore` only for events that are high risk by nature (your code then responds itself). `minimumAction` sets a floor (Monitor mode: decisions only).
- Record with `Security::record()` / `recordFor($user, ...)`, `->middleware('security.track:payouts.requested')` or `Security::listen(Event::class, 'payouts.requested', ...)`.
- Context: up to 20 small scalars (amounts, ids, counts). **Never** passwords, tokens, card or account numbers.

## 2. Gate the action

| Need | Use |
|---|---|
| Stop or challenge a risky action before it happens | `Security::check('payouts.requested', context: [...])->authorize();` |
| A hint before submitting ("needs your authenticator app") | `Security::preview(...)->requiresFreshTwoFactor()` (records nothing) |
| Always demand a fresh authenticator code on a route | `->middleware('security.confirm:2fa,5')` (add `,password` to let accounts without 2FA use their password; default sends them to set up 2FA) |
| The same inside an action | `Security::requireFreshTwoFactor(5);` |

`check()` fails open (allows) when Aegis is off or the category is switched off. JSON callers get `423` with `confirm_url` (challenge) or `403` (denied). Help article for end users already exists: `/help/confirm-with-authenticator`.

## 3. React and stream

- Own consequences: `Security::onAction(SecurityAction::Suspend, FreezeWallet::class)` (class with `handle(SecurityActionTaken $taken)`). Undo them on `SecurityAccountRestored`. Keep handlers quick; queue slow work.
- Streaming: queued listeners on `App\Events\Security\*` forwarding `$event->toExport()` (schema 1, no personal data). `Security::exportUserReferences()` adds a hashed user reference. Read destination URLs and tokens from an integration (`IntegrationCatalog`), never `.env`.

## 4. Signals

Ready-made, scoped to the types you name (none on by default):

```php
Security::signal(VelocitySignal::for('payouts.requested', max: 5, perMinutes: 60));
Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', above: 50_000_000));
Security::signal(ThresholdSignal::on('payouts.requested', 'amount_kobo', timesUsual: 5));
Security::signal(TimeWindowSignal::for('payouts.requested', outside: '06:00-22:00', timezone: 'Africa/Lagos'));
```

Custom: `php artisan make:security-signal UnusualRefund` (creates `app/Security/Signals/UnusualRefundSignal.php` and a test), then `Security::signal(UnusualRefundSignal::class)`. No network calls; at most one indexed query.

## 5. Site assistant limits (append-only)

```php
AssistantDataPolicy::block('wallets');                         // whole table
AssistantDataPolicy::block('bank_accounts', ['account_number']); // columns only
AssistantMetrics::define('payouts.last_30_days', 'Payouts', 'Count and total in 30 days.', 'view_billing', fn () => [...]);
AssistantTools::register(MyWallet::class);                     // extends App\Ai\Tools\AssistantTool
WidgetDataTools::register(MyWallet::class);                   // also offer it to the third-party widget (MCP)
AssistantTools::register(PayoutTrends::class, [AssistantIntent::Analytics]); // staff analytics mode only
AssistantGuard::rule(AssistantVerdict::OTHER_PEOPLE, '/\bwallet balances?\b/');
AssistantIntentRouter::rule(AssistantIntent::Analytics, '/\bpayouts?\b/'); // routing weight only, never access
AssistantKnowledge::add('docs/runbook.md');                   // guide for the staff guidance mode
```

Every new table that holds secrets or other people’s personal data should be blocked. Figures return counts and totals only. Widget tools leave the site: no names, emails or other people’s records. Widget reads are recorded as `mcp.tool_called`; see `project-docs/widget-data-connection.md`.

## 6. Tests

Write a feature test for each hook you add (Ìpìlẹ̀ non-negotiable 4):

```php
$security = Security::fake()->decide('payouts.requested', SecurityDecision::challenge());

$this->actingAs($user)->post('/payouts', ['amount_kobo' => 500_000])->assertRedirect(route('security.confirm'));

$security->assertChecked('payouts.requested', fn (?User $user, array $context) => $context['amount_kobo'] === 500_000);
```

Also: `assertRecorded()`, `assertNotRecorded()`, `assertNothingRecorded()`, `assertPreviewed()`. Use the real monitor (no fake) when testing scoring, incidents or responses.

## Ask first

- Who sees the results? Aegis pages need `view_security` / `manage_security`; a product page showing security data needs its own permission.
- Monitor or enforce? Aegis responses only happen in **Monitor and respond** mode (an admin setting); `check()->authorize()` and `security.confirm` act in every mode.
