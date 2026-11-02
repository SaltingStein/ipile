# Feature Specification: Action Audit Log

**Status**: Implemented (approved by review of the pull request that introduces it)  
**Author**: Ìpìlẹ̀ maintainers, drafted with Claude Code  
**Date**: 2026-11-02  
**Last Updated**: 2026-11-02

---

## Overview
There was no record of who changed what. `security_events` (Aegis) records threat signals for risk scoring, not the actions people take. The SDGP requires an audit trail for critical actions: role and permission changes, account status changes, settings, integrations, payments and account deletion must be attributable to an actor, with the values before and after, without the log itself becoming a store of personal data or secrets.

---

## User Flow
1. An admin changes something that matters (grants a role, suspends a user, rotates an integration credential, changes a setting).
2. An audit entry is written in the same request: action, actor, the record affected, changed fields before and after, IP address and user agent.
3. Personal fields are recorded as changed with the value `[redacted]`; secrets are dropped entirely.
4. A person with the `view_audit_log` permission opens Admin → Audit log and filters by action.

---

## Scope

### In Scope
- `audit_logs` table and `AuditLog` model (append-only).
- `Audit::record()` for actions that are not a model save, and the `Auditable` trait for model created, updated, deleted, force-deleted and restored events.
- `Auditable` on `User`, `Payment`, `Setting`, `Integration`, `LegalDocument` and `Team`.
- Explicit entries for role changes, role permission grants and revokes, account deletion and data export.
- `view_audit_log` permission (granted to `admin`) and the Admin → Audit log page.

### Out of Scope
- Exporting the audit log, and retention or archiving of old entries.
- Auditing read access.
- Tamper evidence beyond the application (hash chaining, WORM storage).

---

## Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-01 | Changes to audited models record the action, actor, subject, changed fields before and after, IP and user agent. | Must |
| FR-02 | Role and permission changes record the roles or permission before and after. | Must |
| FR-03 | Fields named in `$auditRedacted` are recorded as `[redacted]`; fields matching password, secret, token, recovery code, API key, private key or credential are dropped. | Must |
| FR-04 | Settings whose key names a credential (hash, secret, token, password, key) have their value redacted; the setting key is always recorded. | Must |
| FR-05 | Audit entries cannot be updated or deleted through the model. | Must |
| FR-06 | Routine bookkeeping (last sign-in time, timestamps, remember token) is not recorded. | Should |
| FR-07 | The audit log page requires `view_audit_log` and filters by action prefix. | Must |

---

## Non-Functional Requirements

| Category | Requirement |
|----------|-------------|
| Performance | One insert per audited change; `action` and the subject morph are indexed; the page is paginated. |
| Reliability | Entries are written in the same request as the change. |
| Security | No secrets or personal values in entries; values truncated to 1,000 characters; the page is permission-gated. |
| Compliance | SDGP auditability; ISO 27001 logging; NDPR data minimisation (no personal data in the log). |

---

## Data and State Changes

| Entity | Operation | Description |
|--------|-----------|-------------|
| `audit_logs` | CREATE | New table: `actor_id` (null on actor deletion), `action`, `auditable_type`/`auditable_id`, `changes` (JSON), `ip_address`, `user_agent`, `created_at`. |
| `permissions`, `role_has_permissions` | CREATE | `view_audit_log`, granted to `admin`. |
| `audit_logs` | READ | Admin → Audit log. |

---

## Related ADRs

- [ADR-20261102-Append-Only-Audit-Log](../adr/ADR-20261102-Append-Only-Audit-Log.md)

---

## API Endpoints (if applicable)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/audit-log` | Inertia page, `permission:view_audit_log`, optional `action` prefix filter. |

---

## Risks and Edge Cases

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| A new personal field is added to an audited model and logged in clear | Medium | High | Non-negotiable 7, the Sentinel repository rules, and a test pattern to copy; `$hidden` fields are always redacted. |
| Query-builder updates bypass model events | Medium | Medium | Documented: use model saves or `Audit::record()` for critical changes. |
| The table grows without bound | Low | Low | Out of scope for this release; entries are small and indexed. |

---

## Rollback Plan
1. Revert the commit.
2. Roll back `2026_11_02_120100_add_view_audit_log_permission` and `2026_11_02_120000_create_audit_logs_table` (the audit history is lost; export it first if needed).

---

## Dependencies
- spatie/laravel-permission (existing).

---

## Testing Strategy

- Unit tests: none.
- Integration tests: `tests/Feature/Audit/AuditLogTest.php` (role changes, permission grants, redaction, passwords, status changes, bookkeeping ignored, settings, credential settings, integrations, payments, append-only, page permission, listing and filtering, seeded permission).
- E2E tests: none.
