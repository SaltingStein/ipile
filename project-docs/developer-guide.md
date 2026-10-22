# Building on Ìpìlẹ̀: developer guide

Ìpìlẹ̀ gives you sign-in, two-factor, teams, an admin area, payments, SMS, email, a versioned API and a Help Center. This guide shows the quickest way to build *your* product on top of it, as short "I want to…" recipes.

The recipes are the recommended path, not rules. If you prefer another way, go ahead. Only a few things are non-negotiable, and they are listed at the end.

---

## Quick start

```bash
composer setup     # installs everything, creates .env, migrates, then asks you to create the super admin
composer dev       # runs the app, queue worker and Vite together
```

Open the app, sign in as the super admin, and you land on the dashboard. While the app runs locally, the dashboard shows a short "Make this app yours" checklist.

> Blank page after `php artisan serve`? Either keep `npm run dev` running, or run `npm run build`. A leftover `public/hot` file from a crashed Vite run also causes it: delete the file.

---

## Where your code goes

Keep your product in these places and Ìpìlẹ̀ updates stay painless.

| What | Where |
|---|---|
| Your pages' routes | `routes/app.php` |
| Your API routes | `routes/app-api.php` (everything under `/api/v1` is documented automatically) |
| Your sidebar links | `resources/js/appNavigation.ts` |
| Your pages | `resources/js/pages/` (one `.tsx` file per page) |
| Shared UI pieces | `resources/js/components/ui/` (`Panel`, `StatTile`, `EmptyState`, `Button`, `TextField`, `Checkbox`, `StatusMessage`) |
| Help for your users | `resources/help/*.md` |
| Controllers, models, services | the usual Laravel places (`app/Http/Controllers`, `app/Models`, `app/Services`) |

Ìpìlẹ̀'s own routes live in `routes/web.php` and `routes/api.php`. You can edit them, but then a boilerplate update may conflict.

---

## I want to…

### …make the dashboard mine

- The page: `resources/js/pages/Dashboard.tsx`. Replace any section; the stat tiles and the "Your workspace" placeholder are examples.
- Its data: `app/Http/Controllers/DashboardController.php`. Whatever you return in the props array arrives as props in the page.

```php
return Inertia::render('Dashboard', [
    'openOrders' => Order::query()->whereBelongsTo($request->user()->currentTeam)->open()->count(),
]);
```

### …add a page

```bash
php artisan ipile:page Reports --nav
```

This creates the page, its controller, a route in `routes/app.php`, a sidebar link, a feature test and a draft help article. Run it with no name and it asks you each question instead.

| Option | Does |
|---|---|
| `--nav` | adds a sidebar link |
| `--permission=view_reports` | only users with that permission can open it (the link is hidden from everyone else) |
| `--public` | anyone can open it, signed in or not |
| `--path=/reports/monthly` | custom URL (default: the name in kebab-case) |
| `--no-help` | skip the help article |

It never overwrites anything. If a file, route name or URL is taken, it tells you and changes nothing. The templates it uses are in `app/Services/Scaffolding/stubs`; edit them if you want new pages to start differently.

Prefer doing it by hand? Add a route to `routes/app.php` and a page in `resources/js/pages/` wrapped in `AppLayout`. That is all `ipile:page` does.

### …add a link to the sidebar

Add a line to `resources/js/appNavigation.ts`:

```ts
{ label: 'Reports', href: '/reports', permission: 'view_reports' },
```

`permission` is optional. It only hides the link, so protect the route too (next recipe). Operators can also add links without code in **Admin → Sidebar menu**.

### …limit something to certain users

Ìpìlẹ̀ uses roles and permissions ([spatie/laravel-permission](https://spatie.be/docs/laravel-permission)). The super admin passes every check.

1. **Create the permission:** add it to `RolesAndPermissionsSeeder::PERMISSIONS` (and to a role in `ROLES` if it should be granted by default), then run `php artisan db:seed --class=RolesAndPermissionsSeeder`.
2. **Grant it:** in **Admin → Roles & permissions**.
3. **Protect the route:** `->middleware('permission:view_reports')`.
4. **Hide UI bits:** in React, `<Can perform="view_reports">…</Can>`; in PHP, `$user->can('view_reports')`.

Always protect the route or controller. Hiding a button is a convenience, not security.

### …work with teams

- The team the user is working in: `$request->user()->currentTeam` (they switch it at the top of the page).
- Scope team data to it: `Project::query()->whereBelongsTo($request->user()->currentTeam)`.
- Check team rights with the existing policy: `$this->authorize('update', $team)` (owners and team admins), `'view'` (any member), `'manageMembers'`, `'delete'`.

### …add a setting admins can change

Operator settings live in the database and are edited in the admin area, never in `.env`.

1. **Read and save it** with the cached `Setting` model, ideally behind a small service like `app/Services/BrandingSettings.php`:
   ```php
   Setting::valueOf('reports.weekly_email', '1') === '1';
   Setting::store('reports.weekly_email', $enabled ? '1' : '0');
   ```
2. **Add the admin page:** a controller plus a page wrapped in `AdminLayout` (copy `Admin/Theme.tsx` and its controller as a starting point). Put the routes in `routes/app.php` with `->middleware(['permission:manage_settings', 'security.track:admin.settings_changed'])`. The second middleware records the change in the security log.
3. **List it in the admin menu:** add an entry to the right group (or a new group) in `resources/js/adminNavigation.ts`, with the same permission as the route.

**Secrets** (API keys, passwords) belong in **Integrations** instead, which encrypts them and never sends them back to the browser (next recipe).

### …connect a third-party service

Credentials are entered in **Admin → Integrations** and stored encrypted.

- **Use one that exists** (payments, SMS, email, analytics, AI): `app(IntegrationManager::class)->enabledConfig('paystack')` returns its settings, or `null` when it is switched off.
- **Add a new one:** add an `IntegrationDefinition` to `app/Services/Integrations/IntegrationCatalog.php` (fields, help text, optional connection tester). The admin form is generated from it.

### …use an AI model in my feature

Ìpìlẹ̀ uses [Laravel's AI SDK](https://laravel.com/docs/ai-sdk) (`laravel/ai`). Operators connect a model in **Admin → Integrations → AI models**: Anthropic, OpenAI, Google Gemini, OpenRouter, Ollama or any OpenAI-compatible server. Keys never go in `.env`.

```php
use App\Services\Ai\AiModels;
use function Laravel\Ai\agent;

$provider = app(AiModels::class)->provider('openai');   // null when the operator has not switched it on

$summary = agent('Summarise support tickets in two sentences.')
    ->prompt($ticket->body, provider: [$provider]);
```

For anything bigger, make an agent class (`php artisan make:agent TicketSummariser`) and return the provider from its `provider()` method. In tests, use the SDK's fakes (`TicketSummariser::fake(['…'])`) so no real model is called.

### …send an email or SMS

For something users should be told about, use a notification event. Admins can then change the wording and channels in **Admin → Notifications**:

```php
Notify::event('report.ready')->to($user)->with(['link' => route('reports')])->send();
```

Adding a new event takes a few lines in `NotificationCatalog`; see [dev notes → Notifications](dev-notes.md#notifications-email-and-sms).

For a one-off SMS: `app(SmsSender::class)->queue('08031234567', 'Your code is 123456', $user)`. Regular Laravel `Mail` works too and goes through the email provider set up in Integrations.

### …run something on a schedule or in the background

- **Schedule** it in `routes/console.php`, next to the existing entries: `Schedule::job(new SendWeeklySummaries)->weeklyOn(1, '08:00');`
- **Slow or bulk work** (emailing many people, calling providers) goes in a queued job: `php artisan make:job SendWeeklySummaries` (it implements `ShouldQueue`). Make it safe to run twice.
- **In production** you need a cron entry running `php artisan schedule:run` every minute, and a queue worker (`php artisan queue:work`). `composer dev` already runs a worker locally.

### …add an API endpoint

Add the route to `routes/app-api.php` inside the `v1` group:

```php
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
```

- Callers send a Sanctum token: users create one in **API tokens**, mobile apps get one from `/api/v1/auth/login`.
- It appears in the API docs at `/docs/api` automatically; there is nothing to write. A test fails if a `/api/v1` route cannot be documented.
- Prefer returning [API resources](https://laravel.com/docs/eloquent-resources), and share business logic with your web controller through a service class.

### …write help for my users

Help articles are Markdown files in `resources/help/`, shown in the app at `/help` (in everyone's sidebar).

```markdown
---
title: Monthly reports
summary: See and download your team's monthly figures.
order: 60
permission: view_reports
draft: true
---

## Download a report

1. Choose **Reports** in the menu.
2. ...
```

- `permission` hides the article from users without it.
- `draft: true` shows it only while the app runs locally; remove it when the article is ready.
- Write for your users, not for developers: what the page is for, and the steps. Update the article whenever you change the feature.

### …watch for risky activity in my feature

Aegis (Admin → Security) already watches sign-ins, account, team, API and admin changes. Add your own sensitive actions in a service provider's `boot()`, then record them where they happen:

```php
use App\Services\Security\Security;
use App\Services\Security\SecurityEventDefinition;

Security::category('wallets', 'Wallets', 'Payouts and bank details.');
Security::define(new SecurityEventDefinition('payout.requested', 'wallets', 'Payout requested',
    'Money leaving the platform.', sensitivity: 1.0));

// where it happens (or ->middleware('security.track:payout.requested') on the route)
Security::recordFor($user, 'payout.requested', ['amount_kobo' => $amount]);
```

- Your events are scored with the built-in signals and the Markov models, open incidents, and appear in Threat monitoring and Incidents. Admins can switch your category off in Monitoring settings.
- **Stop or challenge it first:** `Security::check('payout.requested', context: [...])->authorize()` asks for a fresh authenticator code or blocks the action when Aegis judges it risky. `Security::preview()` gives the same answer without recording anything.
- **Demand a fresh code on a route:** `->middleware('security.confirm:2fa,5')`.
- **Add your own consequences:** `Security::onAction(SecurityAction::Suspend, FreezeWallet::class)`; give an event a floor with `minimumAction:`.
- Add your own evidence with `Security::signal(MySignal::class)`, and stream activity by listening for the events in `App\Events\Security` (each has a privacy-safe `toExport()`).
- Context values must be small and non-secret: never passwords, tokens or card numbers.
- Every hook with examples, tests (`Security::fake()`, `php artisan make:security-signal`) and recipes for fintech, marketplace and education: [Extending Aegis](aegis-extension-guide.md). How it works inside, the Markov models and every option: [dev notes → Aegis](dev-notes.md#aegis-security-monitoring).

### …change how the app looks

- **Name, logo, version:** Admin → Branding. Your brand never renames Ìpìlẹ̀; the small "Built on Ìpìlẹ̀" footer credit and the About page can each be switched off there.
- **Colours and landing page:** Admin → Theme. To add your own theme, see [dev notes → Themes](dev-notes.md#themes-and-styling).
- **Writing components that work in every theme:** use the `slate` (neutrals) and `emerald` (accent) colour classes rather than fixed colours.

### …work with AI coding agents

Claude Code, Codex, Cursor, Copilot, Gemini and similar tools read project instructions automatically (`CLAUDE.md`, `AGENTS.md` and skills, generated by Laravel Boost). Ìpìlẹ̀'s instructions teach agents these recipes and the non-negotiables. Agents suggest the Ìpìlẹ̀ way, then follow your lead if you want something different, and they keep the Help Center up to date when they change something users see (new articles arrive as drafts for you to review).

Ìpìlẹ̀ skills your agent loads when relevant: `ipile-pages`, `ipile-settings`, `ipile-notifications`, `ipile-api` and `ipile-help-docs`.

- Pick your agents: `php artisan boost:install`.
- After pulling an Ìpìlẹ̀ update: `php artisan boost:update` to refresh the instructions.
- Ìpìlẹ̀'s instructions live in `.ai/guidelines/ipile.md` and `.ai/skills/ipile-*`. Add your product's own conventions as new files there (for example `.ai/guidelines/my-product.md`) and run `php artisan boost:update`; they are merged in and survive Ìpìlẹ̀ updates.

### …teach the AI assistant about my data

The site assistant (Admin → Platform → Site assistant) can report business figures. Add yours to `AssistantMetrics::definitions()` and its `values()` method in `app/Services/Assistant/AssistantMetrics.php`.
- **Give each figure a permission.** A person only gets a figure if an admin ticks it and they have that permission.
- **Return counts and totals only**, never names or contact details.
- **Name your columns.** The assistant's data block refuses protected tables, protected columns and `select *` on tables with protected columns, so select the columns you need.

Add these from a service provider instead of editing Ìpìlẹ̀ files: `AssistantMetrics::define()` for figures, `AssistantTools::register()` for tools, `AssistantDataPolicy::block()` for your sensitive tables and `AssistantGuard::rule()` for screening rules. See [Extending Aegis → the site assistant](aegis-extension-guide.md#7-extend-the-site-assistant).

If the site uses a third-party assistant widget with **Data access** on, the widget's service reads through `/mcp/widgets` with the same figures and data block. Your own tools reach it only if you also call `WidgetDataTools::register()`; see [the widget data connection guide](widget-data-connection.md).

Staff can also use two extra modes of the same assistant, **Site guidance** and **Analytics**, once an admin switches them on (Admin → Site assistant → Staff modes) and their role has `use_assistant_guidance` or `use_assistant_analytics`. Each message is routed automatically. For a staff-only insight, register the tool for that mode: `AssistantTools::register(PayoutTrends::class, [AssistantIntent::Analytics])`. To let the guidance mode answer from your own runbook, call `AssistantKnowledge::add('docs/runbook.md')`.

---

## Before you ship a change

```bash
php artisan test --compact     # or a single file / --filter=Name
vendor/bin/pint --dirty        # PHP formatting
npm run typecheck              # TypeScript
npm run build                  # production assets
```

---

## Taking Ìpìlẹ̀ updates

1. Add the boilerplate as a second remote once: `git remote add ipile <ipile repo url>`.
2. Pull updates when you want them: `git fetch ipile && git merge ipile/<branch>` (the branch the Ìpìlẹ̀ team releases from).
3. Run `composer install`, `php artisan migrate`, `npm install`, `php artisan boost:update`, then the checks above.

Code in the places listed under "Where your code goes" rarely conflicts. If you changed an Ìpìlẹ̀ file, expect to resolve that file by hand.

---

## The non-negotiables

Everything else is a suggestion. These protect your users:

1. **Admin pages and sensitive routes check a permission.** Being signed in is not enough: guest accounts are signed in too.
2. **Secrets are stored encrypted and never sent to the browser.** Use Integrations for third-party credentials.
3. **Operator settings live in the database and the admin area, not in `.env` or code**, so changing them never needs a deploy.
4. **New behaviour comes with a test.**

---

## More detail

- [Developer notes](dev-notes.md): how notifications, themes and Aegis security monitoring work inside.
- In the running app: `/docs/api` (API reference) and `/developers` (developer portal), if enabled in Admin → API documentation.
