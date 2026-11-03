---
name: ipile-settings
description: "Adds settings that operators change from the Ìpìlẹ̀ admin area, and third-party integrations with credentials. Use when the developer wants something configurable, a toggle or option for admins, an admin settings page, an API key or credentials for an external service, or asks to move a value out of .env or code into the dashboard."
---

# Ìpìlẹ̀ settings and integrations

Operator-changeable values live in the database and are edited in the admin area. `.env` only holds bootstrap values (database, `APP_KEY`). Changing operator behaviour must never need a deploy.

## Which mechanism

| The value is… | Use |
|---|---|
| A secret or a third-party credential (API key, password, token) | **Integrations** (`IntegrationCatalog`), encrypted and write-only |
| A normal option (toggle, number, text, choice) | The **`Setting`** model behind a small settings service |
| Per-user or per-team data | A real column or table, not `Setting` |

## A normal setting

1. **Service** in `app/Services/` (see `BrandingSettings`, `ThemeSettings`): constants for keys, typed getters with defaults, an `update()` method.
   ```php
   public const WEEKLY_EMAIL = 'reports.weekly_email';

   public function weeklyEmailEnabled(): bool
   {
       return Setting::valueOf(self::WEEKLY_EMAIL, '1') === '1';
   }
   ```
   `Setting::valueOf()` is cached; `Setting::store()` clears the cache. Values are strings.
2. **Controller** with `edit` and `update` (see `Admin\ThemeSettingsController`): validate, call the service, `back()->with('status', __('… saved.'))`.
3. **Routes** in `routes/app.php`:
   ```php
   Route::middleware('permission:manage_settings')->group(function () {
       Route::get('/admin/reports', [ReportSettingsController::class, 'edit'])->name('admin.reports.edit');
       Route::put('/admin/reports', [ReportSettingsController::class, 'update'])
           ->middleware('security.track:admin.settings_changed')->name('admin.reports.update');
   });
   ```
   `security.track:admin.settings_changed` records the change in the Aegis security log. Use a dedicated permission instead of `manage_settings` if a different group of people should manage it.
4. **Page** in `resources/js/pages/Admin/` wrapped in `AdminLayout`, using `Panel`, `TextField`, `Checkbox`, `Button` and `useForm`. Do not add a second `<h1>`; `AdminLayout` renders the title.
5. **Admin menu:** add `{ title, href, description, permissions }` to `resources/js/adminNavigation.ts` with the same permissions as the route. Ìpìlẹ̀'s groups are Appearance, Post manager, Forms, Campaigns, Security, Platform, Developers and Legal & privacy; for product features add a group named after the product area (e.g. "Billing").
6. **Tests:** managers can view and save; others get 403; invalid input is rejected; the value takes effect.

## A per-team setting

When each team should choose for itself (e.g. "send our team a weekly summary"):

1. A column on `teams` via a migration (`php artisan make:migration add_weekly_summary_to_teams_table`), with a sensible default, plus a cast on `Team`.
2. Edit it where team settings already live (the team page, `resources/js/pages/Teams/Show.tsx`, "Team details") or on your own team page, saved by a controller that calls `Gate::authorize('update', $team)` (owner and team admins).
3. Read it from `$request->user()->currentTeam` or the team being processed.
4. Tests: owner/admin can change it, a plain member gets 403, a non-member gets 404.

If the developer wants both (a site-wide on/off switch for operators and a per-team choice), use a `Setting` for the switch and the column for the team choice, and honour both.

## A new integration

Add an `IntegrationDefinition` to `app/Services/Integrations/IntegrationCatalog.php` with its `IntegrationType`, label, description and `IntegrationField`s (`IntegrationField::secret()` for secrets, `::select()` for choices, `pattern:` for format checks). The admin form at Admin → Integrations is generated from it. Optionally add an `IntegrationTester` for the "Test connection" button.

Read it where needed: `app(IntegrationManager::class)->enabledConfig('provider')` returns the fields, or `null` when disabled. Never log or return secrets, and never put them in Inertia props.

Confirm field names and endpoints against the provider's current documentation.

## AI models

AI providers (Anthropic, OpenAI, Gemini, OpenRouter, Ollama, OpenAI-compatible) are integrations of type `ai`, used through `laravel/ai`. Never read AI keys from `.env` or `config/ai.php`; get the operator's provider at runtime: `app(AiModels::class)->provider('openai')` (null when switched off) and pass it to an agent: `->prompt($text, provider: [$provider])`, or return it from an agent's `provider()` method. Without a provider, agents use the dashboard default (`AiModels::defaultProvider()`: the site assistant's model, else the first switched-on one), never `.env`. A new AI provider needs a catalog entry, a driver in `AiModels::DRIVERS`, and a one-line tester subclass of `AiModelTester`. Test agents with the SDK fakes (`MyAgent::fake([...])`), never a real model.

## If the developer wants something different

A config file is fine for values that are genuinely developer-only and never changed by an operator (say so in one sentence if it looks operator-facing). A dedicated settings table is fine for structured settings. Encrypted storage for secrets and a permission check on the admin route are not optional.
