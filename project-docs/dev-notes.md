# Ìpìlẹ̀ developer notes

New to Ìpìlẹ̀? Start with the task-based [developer guide](developer-guide.md); these notes go deeper.

How to extend the parts of the boilerplate that are shared across the app. Settings that administrators change (provider keys, wording, colours) live in the dashboard, not in code or `.env`. Code only defines *what is possible*.

- [Notifications (email and SMS)](#notifications-email-and-sms)
- [Themes and styling](#themes-and-styling)
- [Aegis security monitoring](#aegis-security-monitoring)

---

## Notifications (email and SMS)

All email and SMS about things that happen in the app go through one entry point, `Notify`. Administrators choose the channels and edit the wording for each event in **Admin → Notifications**.

```php
use App\Services\Notifications\Notify;

Notify::event('payment.succeeded')
    ->to($user)                                   // a User, or a list of users
    ->with(['amount' => 'NGN 5,000.00', 'reference' => $payment->reference])
    ->send();

// Someone without an account:
Notify::event('team.invitation')
    ->toEmail('ada@example.com')
    ->toPhone('08031234567')                      // local or +234 format
    ->with([...])
    ->send();
```

### How it works

| Piece | Role |
|---|---|
| `app/Services/Notifications/NotificationCatalog.php` | Every event: key, channels, placeholders, default wording |
| `app/Services/Notifications/Notify.php` | Entry point; returns a `PendingNotification` builder |
| `app/Services/Notifications/NotificationSettings.php` | Reads and writes the dashboard choices; fills in `{placeholders}` |
| `app/Notifications/EventNotification.php` | The Laravel notification that is actually sent |
| `app/Notifications/Channels/SmsChannel.php` | SMS delivery through Telco SMS (SMPP) in Integrations |

- **Email** goes through the `dashboard` mailer: ZeptoMail when it is enabled in Admin → Integrations, otherwise the fallback mailer.
- **SMS** is skipped when Telco SMS is not enabled, or when the recipient has no phone number (`users.phone_number`).
- **Failures never break the caller.** If sending fails, the error is reported to the log and the action that triggered it (a payment webhook, a sign-up) still completes.
- Laravel's own **verification** and **password reset** emails keep their notification classes, but their wording comes from the `auth.verify_email` and `auth.password_reset` templates (see `AppServiceProvider`).

### Adding an event

**1. Describe it** in `NotificationCatalog::all()`:

```php
new NotificationEvent('account.suspended', 'Account', 'Account suspended',
    'Sent when an administrator suspends an account.',           // shown in the dashboard
    $mailAndSms,                                                 // channels it can use
    $mail,                                                       // channels on by default
    ['reason' => 'Unusual activity', 'link' => url('/contact')], // placeholders => example values (used by "Send test")
    'Your {app_name} account has been suspended',                // default email subject
    "Your account was suspended on {time}.\n\nReason: {reason}", // default email body; a blank line starts a new paragraph
    '{app_name}: your account was suspended. Reason: {reason}',  // default SMS text, or null for email only
    'Contact support',                                           // optional email button; it opens {link}
),
```

- `{name}`, `{app_name}` and `{time}` are available in every event. Don't list them.
- Add `required: true` only when people cannot use their account without the email (verification, password reset, invitations). Administrators can still edit the wording but cannot switch the email off.
- The event appears in Admin → Notifications straight away.

**2. Send it** where the thing happens:

```php
Notify::event('account.suspended')
    ->to($user)
    ->with(['reason' => $reason, 'link' => route('contact')])
    ->send();
```

Where to put the call:

| The trigger is… | Do this | Example |
|---|---|---|
| Your own code (controller, service, job) | Call `Notify` right there | `PasswordController`, `TwoFactorAuthenticator` |
| A Laravel or package event (`Registered`, `Login`, …) | Add a listener in `app/Listeners`. It is discovered automatically from the `handle()` type hint | `SendWelcomeNotification`, `SendNewSignInAlert` |
| Something that can repeat (webhook retries, queued retries) | Send only when the state actually changes | `PaymentWebhookProcessor` |

**3. Test it:**

```php
Notification::fake();

// …perform the action…

Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) =>
    $notification->event === 'account.suspended' && $notification->data['reason'] === 'Unusual activity');
```

For someone without an account, use `Notification::assertSentOnDemand(EventNotification::class, …)`.

### Common mistakes

- **Typo in the key:** `Notify::event()` throws `InvalidArgumentException` immediately, so you see it in development.
- **Value passed but not listed as a placeholder:** administrators can't use it. The dashboard rejects unknown `{placeholders}`.
- **Placeholder listed but not passed:** the raw text (`{reason}`) appears in the message.
- **Sending email with `Mail::` or a custom `Notification` class for an app event:** administrators can't control it. Add a catalog event instead.

### Current events

| Key | Sent from |
|---|---|
| `auth.welcome` | `SendWelcomeNotification` (on `Registered`; guests skipped) |
| `auth.verify_email`, `auth.password_reset` | Laravel's `VerifyEmail` / `ResetPassword`, using the dashboard wording |
| `auth.magic_link` | `MagicLinkController` queues the encrypted `SendMagicLink` job; required email, editable wording, single-use signed URL |
| `security.password_changed` | `PasswordController`, `PasswordResetter` |
| `security.two_factor_enabled` / `_disabled` | `TwoFactorAuthenticator` |
| `security.new_sign_in` | `SendNewSignInAlert` (a browser not seen before for this account; the first sign-in after sign-up doesn't alert) |
| `team.invitation` | `TeamManager::invite()` |
| `payment.succeeded` / `payment.failed` | `PaymentWebhookProcessor` (once, when the payment settles) |

### Not built yet

- Catalog notifications send synchronously. Slow and bulk callers queue their own jobs; magic-link delivery uses `SendMagicLink` (`ShouldQueue`, `ShouldBeEncrypted`) and needs a queue worker. Its database delivery reservation prevents duplicate attempts. Delivery errors retain the catalog's existing reporting behavior; users can request another link.
- No per-user opt-outs. Add one before introducing marketing-type events.
- SMS has a delivery log (`sms_messages`); email does not.

---

## Themes and styling

Administrators pick a theme in **Admin → Theme**. A theme sets:

1. the colours of **every** page (public site, sign-in, dashboard, admin), and
2. the **landing page** layout (`resources/js/pages/Landing/*`).

| Theme | Colours | Landing page |
|---|---|---|
| `fintech`, Fintech Clean (default) | Dark slate, emerald accent | `LayoutAlpha` (headline card) |
| `bold`, Bold Dark | Deep navy, cyan accent | `LayoutBeta` (centred hero) |
| `minimal`, Minimal Light | Light stone, deep green accent | `LayoutGamma` (text-first) |

### How it works

- `App\Services\ThemeSettings` stores the choice (setting `site.theme`) and lists the themes.
- The server puts `data-theme="…"` on `<html>` (`resources/views/app.blade.php`). `resources/js/app.js` updates it after Inertia navigation, so a theme change applies without a full reload.
- `resources/css/app.css` redefines Tailwind's colour variables for each theme. Tailwind v4 classes read those variables (`bg-slate-950` → `var(--color-slate-950)`), so one set of classes serves every theme.

### Writing components that work with every theme

Use the **base palette** only. Think of the colour names as roles, not literal colours:

| Use | Class |
|---|---|
| Page background | `bg-slate-950` |
| Panels, cards | `bg-slate-900` (often `/80`) |
| Borders | `border-slate-800` (inputs: `border-slate-700`) |
| Main text / headings | `text-slate-100` / `text-slate-50` |
| Secondary text | `text-slate-300`, `text-slate-400` |
| Accent (buttons, links, highlights) | `bg-emerald-500 text-slate-950`, `text-emerald-400`, `bg-emerald-500/10` |
| Warning / error / danger | `amber-*` / `rose-*` / `red-*` |

Rules:

- **Don't use `white` or `black`** for text or surfaces. Use `slate-50` / `slate-950`, which a light theme inverts. (Exception: something that must stay white whatever the theme, such as the 2FA QR code background, `bg-[#fff]`.)
- **Don't hard-code other palettes** (`cyan`, `stone`, hex colours) in shared UI. They won't follow the theme.
- Only the theme's **landing page** decides layout differences (centred vs. card vs. text-first). Colours still come from the palette.
- Public pages use `PublicLayout` (top nav and footer); signed-in pages use `AppLayout` or `AdminLayout`. All of them follow the theme.

### Adding a theme

1. **Define it** in `ThemeSettings::THEMES`: a key, label, description, landing page component and four preview swatches (background, surface, text, accent).
2. **Add its colours** to `resources/css/app.css` in a `[data-theme='your-key'] { … }` block that redefines the `--color-slate-*` and `--color-emerald-*` variables (and `amber`/`rose`/`red` for a light theme). Copy `bold` (dark) or `minimal` (light) as a starting point. Tailwind's own values are in `node_modules/tailwindcss/theme.css`.
3. **Add a landing page** in `resources/js/pages/Landing/`, or reuse an existing one. Wrap it in `PublicLayout` and use only the base palette.
4. **Run `npm run build`** (or keep `npm run dev` running), then choose the theme in Admin → Theme.
5. **Extend `tests/Feature/Admin/ThemeSettingsControllerTest.php`** if the theme has special behaviour.


---

## Aegis security monitoring

Aegis records security events (sign-ins, permission changes, payments, your own events), scores how risky each one looks, groups risky ones into incidents and, in **Enforce** mode, responds. Administrators run it from **Admin → Security**: **Threat monitoring** (overview), **Incidents**, **Monitoring settings** and **Monitoring guide**.

```php
use App\Services\Security\Security;

Security::record('orders.refunded', ['amount_kobo' => 500000]);   // about the signed-in user
Security::recordFor($order->user, 'orders.refunded');             // about a given account
```

### How it works

Every event goes through one pipeline in `SecurityMonitor::record()`:

| Step | What happens | Where |
|---|---|---|
| 1. Gate | Skipped while the mode is **Off**, or the event's category is switched off (except events with a `forcedScore`) | `SecuritySettings`, `SecurityMode` |
| 2. Signals | Each signal scores 0–1; combined as `100 × (1 − Π(1 − weight × score)) × (0.4 + 0.6 × sensitivity)` | `RiskAssessor`, `Signals/*` |
| 3. Posture | The account's Markov posture chain moves with the event score and decays while quiet | `Markov/RiskPostureChain` |
| 4. Decision score | `max(event score, posture score, forcedScore)` | `SecurityMonitor::process()` |
| 5. Incident | Opened (or the open one updated) when the decision score reaches the incident threshold, or always for `forcedScore` events | `IncidentManager` |
| 6. Response | Enforce mode only: the strongest rung whose threshold is reached, if stronger than the last one taken in this episode | `SecurityResponder` |
| 7. Events | `SecurityRiskAssessed` for every event, `SecurityIncidentOpened` for new incidents; optional AI analysis is queued | `app/Events/Security` |

**Modes:** **Off** (nothing recorded), **Monitor** (the default: recorded, scored, incidents opened, no responses) and **Enforce** (responses run). Monitoring fails open: if anything in the pipeline throws, the error is reported and the user's request continues.

**Default thresholds** (Admin → Monitoring settings):
- **Response ladder:** alert the owner at 60, password re-check at 75, sign out everywhere at 90, suspend never.
- **Other:** incidents open at 60, and AI analysis runs at 70 when switched on.
- **Re-arming:** a rung is not repeated until the account's posture falls below 20.

**Built-in signals:**

| Signal | Weight | Fires when |
|---|---|---|
| Recent failed attempts | 0.7 | Failed sign-ins, rejected 2FA codes or lockouts recently, against the account or from the IP |
| Unusual sequence (Markov) | 0.6 | This event is rare straight after the previous one (needs trained baselines) |
| New device | 0.35 | The browser or app has not been seen on this account (after three earlier events) |
| Burst of activity | 0.3 | Many security events within a minute from the account or IP |
| New network | 0.25 | The IP range (IPv4 /24, IPv6 /48) has not been seen on this account |

### The two Markov models

- **Sequence model** (`Markov/SequenceAnomalyDetector`, `TransitionMatrix`, `BaselineTrainer`):
  - **What it is:** a first-order chain over event types, P(this event | previous event).
  - **Blending:** probabilities are blended from everyone, to the role, to this account (hierarchical Dirichlet smoothing, prior strength 5). An account gets its own baseline after 20 transitions.
  - **Sessions:** a gap of 30 minutes or more starts a new session.
  - **Scoring:** an event 100× less likely than the most likely next event scores 1.0.
  - **Training:** `php artisan security:train`, run nightly at 02:30. Training leaves out events from incidents resolved as **Confirmed attack**, and the model scores nothing until it has been trained.
- **Posture chain** (`Markov/RiskPostureChain`):
  - **What it is:** each account has a probability spread over four levels: normal (counts for 0 points), elevated (35), high (65) and critical (90).
  - **On each event:** the event's score picks a transition matrix:
    - under 30: calm;
    - 30–49: low;
    - 50–74: medium;
    - 75 and over: high.
  - **Decay:** each 30 quiet minutes applies the calm matrix, up to one day's worth at a time.
  - **Effect:** several moderately risky events add up, while one odd event fades.

### Extending Aegis

Register extensions in a service provider's `boot()`. Copy-paste examples for every hook are in [aegis-extension-guide.md](aegis-extension-guide.md); this section explains how they work inside.

**1. Describe your event.** Unknown event keys throw, so typos surface in development:

```php
use App\Services\Security\Security;
use App\Services\Security\SecurityEventDefinition;

Security::category('wallets', 'Wallets', 'Payouts and bank details.');
Security::define(new SecurityEventDefinition(
    'payout.requested', 'wallets', 'Payout requested', 'Money leaving the platform.',
    sensitivity: 1.0,          // 0–1: how much damage this could do in the wrong hands
));
```

Other options on `SecurityEventDefinition`:
- `isFailure`: a failed attempt, which the recent-failures signal counts.
- `actorIsVerified: false`: anyone can cause this event, so responses stop at alerting the owner.
- `forcedScore`: high risk by nature. The event is always recorded and always opens an incident, and your own code carries out the response. The site assistant's `assistant.sensitive_request` uses this.
- `minimumAction`: the weakest response the event may get (e.g. `SecurityAction::StepUp`). `SecurityMonitor::recommend()` raises the threshold's action to it, then caps unverified actors at an alert. In Monitor mode it only shapes decisions.

**2. Record it** in one of four ways:
- **Directly** with `Security::record()` or `Security::recordFor()`.
- **On a route:** `->middleware('security.track:payout.requested')`. It records only when the response succeeds (status below 400).
- **From your own event:** `Security::listen(PayoutRequested::class, 'payout.requested', fn ($e) => $e->user, fn ($e) => ['amount_kobo' => $e->amount])`.

Context is limited to 20 small scalar values (keys up to 50 characters, strings up to 200). Never pass passwords, tokens or card numbers.

**3. Add evidence** with a class implementing `SecuritySignal`: `key()`, `label()`, `description()`, `weight()` (0–1) and `evaluate(SignalContext): SignalResult`.
- **What the context gives you:** the definition, user, IP, device and network hashes, time, previous event and your context.
- **What to return:** `SignalResult::none()`, or `new SignalResult($score, 'Why it fired')`.
- **Register it** with `Security::signal(MySignal::class)`.

**4. React to outcomes** with the lifecycle events in `App\Events\Security`: `SecurityEventRecorded`, `SecurityRiskAssessed`, `SecurityIncidentOpened`, `SecurityActionTaken`, `SecurityIncidentResolved`, `SecurityAccountRestored`. Each has `toExport()`, built by `SecurityExportPayload` (schema 1: ids, types, scores, levels, actions, timestamps; `user_reference` only after `Security::exportUserReferences()`). The four newer ones implement `ShouldDispatchAfterCommit`. The monitor dispatches inside a try/report, so a broken listener never stops incidents or responses.

**5. Decide before acting** with `Security::check()` (records, then decides) or `Security::preview()` (`SecurityMonitor::evaluate()` without saving: posture is projected with `firstOrNew()` and never stored; no event, incident, response or dispatch). `decide()` maps the recommended action to `SecurityOutcome`: StepUp → Challenge, RevokeSessions/Suspend → Deny, else Allow; a challenge becomes Allow when the request's own user confirmed a fresh code within 10 minutes. Nothing scored (off, category off, error) → Allow, except `forcedScore` events, decided from that score. `SecurityDecision::authorize()` throws `SecurityChallengeRequired` (renders a redirect to `/security/confirm` via `redirect()->guest()`, or `423` JSON) or `SecurityActionDenied` (back with a `security` error, or `403`). Both implement `ShouldntReport`.

**6. Fresh two-factor** (`FreshTwoFactor`): the confirmation time is the session key `security.two_factor_confirmed_at`. `security.confirm:2fa,{minutes},{fallback}` (`EnsureFreshTwoFactor`) and `Security::requireFreshTwoFactor()` call `FreshTwoFactor::ensure()`. `markConfirmed()` also calls `session()->passwordConfirmed()`, which clears an Aegis step-up (`EnforceSecurityStepUp` lets `/security/confirm` through). Codes entered on `/confirm-password` and when finishing 2FA setup also mark the session fresh.

**7. Your own consequences:** `Security::onAction()` fills `SecurityResponseHooks` (singleton). `SecurityResponder::respond()` runs the handlers for the response actually taken and dispatches `SecurityActionTaken` (`announce()`); the assistant guard calls the same path with source `assistant`, including when recording failed and it locked the account out directly.

### Site assistant guard rails

The assistant (Admin → Platform → Site assistant) adds its own controls on top of Aegis:
- **Data block:** `AssistantDataPolicy` is a `DB::beforeExecuting` hook that refuses protected tables, columns and `select *` queries while assistant tools run.
- **Screening:** `AssistantGuard` checks every message with fixed rules plus an AI check.
- **Lock-out:** the person is suspended or signed out, as the admin chose. This always records `assistant.sensitive_request` (`forcedScore` 80).
- **Alerts:** a required `security.admin_alert` email goes to security staff.

Suspended accounts are restored from the incident page (`suspend_users`) or with `php artisan ipile:restore-user {email}`.

**Widget data connection (MCP):** with the third-party widget as the source and its integration's **Data access** on (signed mode only), `/mcp/widgets` (`routes/ai.php`, `laravel/mcp`) serves `WidgetDataServer`. `AuthenticateWidgetData` answers 404 when off, 403 outside the configured networks, and 401 unless the bearer is a current claim from `AssistantWidget::claim()`. Such claims then carry `ref`, encrypted with the app key: integration, user id, expiry and a fingerprint of the remember token and password hash. So the widget service, which knows the shared secret, cannot forge, re-date or swap a claim, and sign-out everywhere or a password change ends it. The server's tools are `WidgetDataTool` bridges over `AssistantTool`s from `WidgetDataTools` (Ìpìlẹ̀: `SearchHelpArticles`, `MyAccess`, `BusinessFigures`; builders: `WidgetDataTools::register()`). They run under the data policy, catch every exception (generic message, even in debug), and record `mcp.tool_called` with `actorIsVerified: false`, because calls come from the service's servers; failures record `mcp.access_refused`. Guide for the widget service: `widget-data-connection.md`.

**Assistant modes (staff routing):** `AssistantController::send()` screens first, then `AssistantIntentRouter::route()` picks the mode: `AssistantIntent::EndUserSupport` (default), `CoreGuidance` or `Analytics`. Classification counts matching rules (`RULES` plus `AssistantIntentRouter::rule()`); ties go to guidance, then analytics; no match keeps the session's `assistant.intent`. A staff mode answers only when it is in `assistant.staff_modes`, the user has its permission (`use_assistant_guidance` / `use_assistant_analytics`) and a full account, and the channel is `page`. `widget` is never a staff channel. `SiteAssistant` takes the mode: it adds mode instructions, `SearchSiteGuide` for guidance (searching `AssistantKnowledge`'s files section by section, skipping missing ones), and `AssistantTools::for($user, $mode)`. Each routed message records `assistant.message_routed` (intent, mode, channel, fallback); this stands in for CORE-913's interaction log.

**8. Configurable signals:** `SecuritySignals::register()` takes a class (resolved per event) or a configured instance (kept by `key()`, so re-registering replaces it). `VelocitySignal`, `ThresholdSignal` and `TimeWindowSignal` extend `ScopedSignal`, which returns nothing for event types the builder did not name. Velocity and threshold history use the `security_events (user_id, type, occurred_at)` index; time windows need no query.

**9. Assistant extensions** live in the `AssistantExtensions` singleton, which has no remove methods. `AssistantDataPolicy::blockedTables()` / `protectedColumns()` merge it with the constants (the admin page shows the merged lists); `AssistantMetrics::definitions()` appends builder figures and `values()` calls their closures inside the policy; `SiteAssistant::tools()` appends `AssistantTools::for($user, $mode)` (built from the container with `user`; tools registered with modes only appear in those modes); `AssistantGuard::matchBuilderRules()` runs after Ìpìlẹ̀'s rules with the same how-to exception. `AssistantTool::handle()` is final.

**10. Testing:** `Security::fake()` binds a `SecurityFake` (a `SecurityMonitor` subclass that never calls the parent constructor) as the container instance, so every `Security::` call, `security.track` and `Security::listen()` reach it. It validates event types against the real catalog and keeps records, checks and previews in memory. `make:security-signal` (`App\Console\Commands\MakeSecuritySignal`, stubs in `app/Console/Commands/stubs`) writes to `app/Security/Signals` and `tests/Feature/Security/Signals`.
