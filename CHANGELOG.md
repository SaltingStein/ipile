# Changelog

All notable changes to Ìpìlẹ̀ are recorded here.

## Unreleased

- **Changed:** payment amounts are stored as integer minor units (`payments.amount_minor`) and converted with `App\Support\Money`; webhook amount checks compare integers. The API's `amount` is unchanged and `amount_minor` is added. Run `php artisan migrate`.
- **Changed:** deleting users, payments, blog posts, campaign pages, forms and form responses is now a soft delete. `model:prune` (scheduled nightly) purges them, with their files, after `retention.deleted_records_days` (30); payments are never purged.
- **Added:** an append-only audit log of user and admin actions (roles and permissions, account status, settings, integrations, payments, account deletion), with personal data redacted and secrets dropped. Admin → Audit log, permission `view_audit_log`.
- **Added:** people can download their personal data and delete their account from Settings → Security, or through `GET /api/v1/auth/user/export` and `DELETE /api/v1/auth/user`.
- **Added:** the SDGP governance kit: `docs/specs`, `docs/adr`, `docs/deviations` with templates, `dev-project-docs`, a solution document, a pull request template, governance rules for AI agents (`.ai/guidelines/sdgp.md`, `.github/copilot-instructions.md`, `.cursorrules`) and Sentinel review rules (`.ipile.md`).
- First public release: accounts and two-factor, teams, roles and permissions, the admin area, dashboard-configured integrations (Nigerian payment gateways, SMPP SMS, ZeptoMail), Aegis security monitoring, the site assistant, a versioned API with generated docs, and the Help Center.
