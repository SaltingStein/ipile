---
name: ipile-api
description: "Adds or changes versioned JSON API endpoints in an Ìpìlẹ̀ app under /api/v1, for mobile apps, scripts and integrations, documented automatically at /docs/api. Use when the developer asks for an API, endpoint, REST route, mobile backend, webhook receiver or token-authenticated access to app data."
---

# Ìpìlẹ̀ API endpoints

## Where

Product endpoints go in `routes/app-api.php`, inside the ready `v1` group (`/api/v1/...`, names `api.v1.*`, `auth:sanctum` + `active` + `throttle:api`). Ìpìlẹ̀'s own endpoints are in `routes/api.php`; do not add product routes there.

```php
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:api')->name('reports.store');
```

Add `'verified'` to a route or group when an unverified email must not use it. Add a named limiter from `App\Services\RateLimits` for sensitive actions.

## How

1. **Controller** in `app/Http/Controllers/Api/V1/` (see `TeamController`): short docblock per action (it becomes the API description), `Gate::authorize(...)` for record-level access, validation via `$request->validate()` or a Form Request in `app/Http/Requests/Api/`.
2. **Responses:** an API Resource in `app/Http/Resources/` (see `TeamResource`); `201` for creates; never expose secrets or internal ids you would not show in the UI.
3. **Business logic** in a service class shared with the web controller, so the web UI and API behave the same.
4. **Team data:** scope to `$request->user()->currentTeam` and authorize with `TeamPolicy`.

## Docs come for free

Scramble documents everything under `/api/v1` from the code at `/docs/api` (visibility set in Admin → API documentation). `tests/Feature/Api/ApiDocumentationTest.php` fails the build if a `/api/v1` route cannot be documented. Typed return values, Resources and validation rules give the best docs; no annotations needed.

## Authentication for callers

- Users create tokens in the app (API tokens page); mobile apps call `POST /api/v1/auth/login` (and `/two-factor-challenge` when needed) to get one.
- Token lifetime and idle timeout are set by admins in Admin → Sign-in & 2FA.
- Payment provider webhooks go to `POST /api/webhooks/{provider}` and are verified by signature; follow `WebhookController` for new receivers.

## Tests

In `tests/Feature/Api/V1/`: `Sanctum::actingAs($user)` (or a real token), 401 without a token, 403/404 for other users' records, validation errors as 422 JSON, and the happy path.

## If the developer wants something different

A separate version prefix, a different auth scheme for machine clients, or GraphQL are all reasonable. Point out once that routes outside `/api/v1` are not auto-documented by default (`config/scramble.php` → `api_path`).
