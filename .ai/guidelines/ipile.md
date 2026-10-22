# Ìpìlẹ̀

This app is built on **Ìpìlẹ̀**, a reusable Laravel + Inertia React starter kit: sign-in, two-factor, teams, roles and permissions, an admin area, payments, SMS, email, a versioned API and a Help Center. Developers build their own products on top of it. The developer guide is `project-docs/developer-guide.md`; deeper notes are in `project-docs/dev-notes.md`.

## Working with the developer

- Suggest the Ìpìlẹ̀ way briefly, with the reason. If the developer wants to do it differently, do it their way. Mention a trade-off once at most; do not repeat it or lecture.
- Hold firm only on the non-negotiables below. If a request would break one, say so plainly and offer a safe way to get the same result.
- Product-specific names are fine in product code. Only Ìpìlẹ̀'s own files stay domain-neutral.
- When a request is ambiguous about **who** may do something, ask one short question before building. In particular: site-wide staff (a global permission such as `view_billing`) or people inside a team (the team's owner/admins through `TeamPolicy`)? And for settings: one value for the whole site, or one per team?
- This project does not use `.ai/rules`; Ìpìlẹ̀'s rules are this section and the `ipile-*` skills.

## Non-negotiables

1. Admin pages and sensitive routes check a permission (`->middleware('permission:…')` or a policy). Being signed in is not enough: guest accounts are signed in too.
2. Secrets (API keys, passwords) are stored encrypted and never sent to the browser. Third-party credentials go in Integrations (`IntegrationCatalog`).
3. Settings an operator might change live in the database and the admin area (the cached `Setting` model), not in `.env` or code. This includes AI providers: when using `laravel/ai`, get the provider with `app(AiModels::class)->provider('openai')` (configured in Admin → Integrations → AI models) instead of the `.env` keys the generic `ai-sdk-development` skill describes; everything else in that skill applies.
4. New behaviour comes with a feature test.

## Where product code goes

| What | Where |
|---|---|
| Page routes | `routes/app.php` (Ìpìlẹ̀'s own are in `routes/web.php`) |
| API routes | `routes/app-api.php`, inside the `v1` group (auto-documented at `/docs/api`) |
| Sidebar links | `resources/js/appNavigation.ts` |
| Admin menu entries | the matching group in `resources/js/adminNavigation.ts`, with the same permission as the route |
| Pages | `resources/js/pages/*.tsx`, wrapped in `AppLayout` (signed-in), `AdminLayout` (admin) or `PublicLayout` |
| Shared UI | `resources/js/components/ui/*` (`Panel`, `StatTile`, `EmptyState`, `Button`, `TextField`, `Checkbox`, `StatusMessage`) |
| End-user help | `resources/help/*.md` |

New pages: prefer `php artisan ipile:page Name [--nav] [--permission=…] [--public] [--path=…] [--no-help]`. It creates the page, controller, route, test, optional sidebar link and a draft help article, and never overwrites files.

## Background and scheduled work

Schedule recurring work in `routes/console.php` with `Schedule::command(...)` next to the existing entries; production needs a cron running `php artisan schedule:run` every minute. Put slow or bulk work (sending to many people, calling providers) in a queued job (`ShouldQueue`) and make it safe to run twice; production needs a queue worker. Notification events send synchronously today, so queue the job that loops over recipients rather than the notification.

## Keep user help current

When you add or change something end users see, create or update the matching article in `resources/help/` in the same change, written for end users (what it is for, the steps; no code). Give new articles `draft: true` so the developer reviews them, and say in your summary which articles you touched. Skip this for purely internal changes. Admin-facing options get an article only when admins need steps to use them, with the admin permission set on it.

## Skills

Load the matching skill before you start: `ipile-pages` (pages, routes, sidebar, permissions, dashboard), `ipile-settings` (admin settings pages and integrations), `ipile-notifications` (emails and SMS users receive), `ipile-api` (`/api/v1` endpoints), `ipile-help-docs` (writing help articles), `ipile-security` (Aegis: protecting sensitive actions, fraud signals, fresh 2FA, assistant limits).

## Before you finish

Run the narrowest relevant tests (`php artisan test --compact --filter=…`), `vendor/bin/pint --dirty --format agent` for PHP, and `npm run typecheck` for TypeScript. Run `npm run build` when the developer is not running `npm run dev`.
