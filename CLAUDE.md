<laravel-boost-guidelines>
=== .ai/ipile rules ===

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

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
