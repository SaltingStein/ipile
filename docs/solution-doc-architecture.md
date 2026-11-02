# Solution and Architecture Document: Ìpìlẹ̀

**Status**: Approved
**Last Updated**: 2026-11-02

> **Projects started from Ìpìlẹ̀:** replace this document with your product's own architecture before writing feature specs. Keep the sections below that still describe the foundation you inherited, and add your product's components, data and integrations.

---

## Purpose

Ìpìlẹ̀ is the company's standard starting point for Laravel applications. It provides the parts every product needs (accounts, teams, permissions, an admin area, payments, messaging, a versioned API, auditing and data-protection features) so product teams start from a foundation that already meets the SDGP.

## Stack

| Layer | Technology |
|---|---|
| Language and framework | PHP 8.3+, Laravel 13 |
| Front end | Inertia.js, React 18, TypeScript, Tailwind CSS 4, Vite |
| API authentication | Laravel Sanctum (personal access tokens) |
| Authorisation | spatie/laravel-permission (roles and permissions), Laravel policies |
| Database | Any Laravel-supported SQL database; SQLite by default for development |
| Queue, cache, sessions | Database drivers by default; swap for Redis in production as needed |
| Tests and style | PHPUnit, Laravel Pint, TypeScript type-check |
| AI agent instructions | Laravel Boost (`.ai/guidelines`, `.ai/skills` → `CLAUDE.md`, `AGENTS.md`) |

## Components

| Component | Where | Responsibility |
|---|---|---|
| Accounts and sign-in | `app/Services/Auth`, `app/Http/Controllers/Auth` | Passwordless and password sign-in, two-factor, trusted devices, social sign-in, sessions |
| Account data rights | `app/Services/Account` | Personal-data export and account deletion (anonymise, then purge) |
| Teams | `app/Services/Teams` | Teams, invitations, team roles |
| Admin area | `app/Http/Controllers/Admin`, `resources/js/pages/Admin` | Permission-gated operator pages, including the audit log |
| Settings and integrations | `Setting`, `Integration`, `app/Services/Integrations` | Operator settings in the database; encrypted third-party credentials |
| Payments | `app/Services/Payments` | Gateway adapters (Paystack, Squad, Flutterwave, OPay), verified webhooks, ledger matching in integer minor units |
| Messaging | `app/Services/Mail`, `app/Services/Sms`, `app/Services/Notifications` | Email (ZeptoMail), SMS over SMPP, notification events |
| Security monitoring (Aegis) | `app/Services/Security` | Risk scoring, incidents and automatic responses |
| Audit trail | `app/Services/Audit`, `AuditLog`, `Auditable` | Append-only record of who changed what |
| API | `routes/api.php`, `routes/app-api.php` | Versioned `/api/v1`, generated documentation |
| Content | Blog, campaign pages, forms, Help Center | Public content and in-app help |

## Data

- **Money** is stored as integer minor units (`payments.amount_minor`) and converted only through `App\Support\Money`. See [ADR-20261102-Integer-Minor-Units-For-Money](adr/ADR-20261102-Integer-Minor-Units-For-Money.md).
- **Deletion** is a soft delete. Records people can delete are purged by `model:prune` after `retention.deleted_records_days`; payments are never purged. See [soft-deletes-retention](specs/soft-deletes-retention.md).
- **Personal data** is exportable and deletable by its owner. Deleted accounts are anonymised immediately and purged after the retention window. See [ADR-20261102-Anonymise-Then-Purge-Account-Deletion](adr/ADR-20261102-Anonymise-Then-Purge-Account-Deletion.md).
- **Audit entries** are append-only and never contain secrets or personal values. See [ADR-20261102-Append-Only-Audit-Log](adr/ADR-20261102-Append-Only-Audit-Log.md).
- **Secrets** (integration credentials, two-factor secrets) are encrypted at rest and never sent to the browser.

### Core tables

| Table | Holds | Deletion |
|---|---|---|
| `users` | Accounts | Soft delete, anonymised on account deletion, purged after retention |
| `teams`, `team_user` | Teams and membership | Hard delete (database cascades) |
| `payments` | Payment records, `amount_minor` | Soft delete, never purged |
| `audit_logs` | Who did what, before and after | Never updated or deleted |
| `settings`, `integrations` | Operator configuration, encrypted credentials | Hard delete |
| `blog_posts`, `campaign_pages`, `forms`, `form_submissions` | Content and responses | Soft delete, purged after retention with their files |
| `security_events`, `security_incidents` | Aegis threat detection | Retained per Aegis settings |

## Scheduled and background work

`routes/console.php` schedules recurring work (including `model:prune` at 02:55 daily). Production needs a cron running `php artisan schedule:run` every minute and a queue worker.

## Security posture

Permission checks on every admin and sensitive route; passwordless sign-in for users and two-factor for staff; named rate limiters on sensitive endpoints; verified payment webhooks; encrypted secrets; structured logs without personal data; an append-only audit trail.

## Known gaps

Tracked for a follow-up release: a unified error envelope with `request_id` on API errors, idempotency keys on retryable POSTs, application-level security headers and CSP, and measured test coverage with an 80% CI gate.
