# ADR-20261102-Append-Only-Audit-Log

**Status**: Proposed (Accepted when the introducing pull request is approved)  
**Date**: 2026-11-02  
**Decision Owner**: Ìpìlẹ̀ maintainers  
**Approved By**: Pull request reviewer

---

## Context
The SDGP requires an audit trail for critical actions. Ìpìlẹ̀ had Aegis `security_events` for threat detection but nothing recording who changed roles, settings, integrations, payments or accounts. The audit trail touches identity and personal data and changes a security boundary (who can see what happened), so it needs an ADR. The log must not itself become a store of personal data or secrets. Spec: [audit-log](../specs/audit-log.md).

---

## Decision
Add an application-level, append-only `audit_logs` table written by an `Auditable` model trait and an `Audit::record()` service. Entries hold the action, actor, subject, changed fields before and after, IP and user agent. Personal fields declared in `$auditRedacted` (and all `$hidden` fields) are stored as `[redacted]`; secret-like keys are dropped; values are truncated. The model refuses updates and deletes. Viewing requires the `view_audit_log` permission.

---

## Options Considered

### Option 1: Own table, trait and service (chosen)
- **Pros**: No new dependency; redaction rules are ours and tested; fits the existing permission and admin patterns; small enough for product teams to understand.
- **Cons**: We maintain it; model events miss query-builder updates.

### Option 2: spatie/laravel-activitylog or owen-it/laravel-auditing
- **Pros**: Mature, feature-rich.
- **Cons**: New external dependency; defaults log full attribute values, so redaction must be configured per model to avoid storing personal data; larger surface than needed.

### Option 3: Database triggers
- **Pros**: Catches every write, including query-builder updates.
- **Cons**: No actor or request context; database-specific; hard to test and to redact.

---

## Rationale
Option 1 gives a privacy-safe default (redaction and secret dropping built in) with no new dependency, and integrates with Spatie permissions and the admin area already in Ìpìlẹ̀.

---

## Consequences

### Positive
- Critical changes are attributable, with before and after values where safe.
- The log can be shown to admins without exposing personal data.

### Negative
- Changes made through the query builder or raw SQL are not captured; critical paths must use model saves or `Audit::record()`.

### Neutral
- The table grows without a retention policy in this release.

---

## Security and Risk Notes
- Append-only is enforced in the application; a database administrator can still alter rows. Tamper evidence (hash chaining or external shipping) is a possible follow-up.
- New personal fields on audited models must be added to `$auditRedacted` (non-negotiable 7, Sentinel rule).
- The actor foreign key is nulled when an actor is purged, so entries outlive accounts.

---

## Review Triggers
- A regulator or customer requires tamper-evident audit records.
- Audit volume makes the table a performance concern.
- A requirement to audit read access.

---

## ISO 27001 Mapping
Which controls does this ADR support?

- [x] A.8 - Change Management
- [x] A.5 - Risk Assessment
- [x] A.8.25 - Secure Development
- [x] A.5.15 - Access Control
- [ ] A.5.29 - Business Continuity
- [x] A.5.37 - Documented Procedures

---

## Related ADRs
- [ADR-20261102-Anonymise-Then-Purge-Account-Deletion](ADR-20261102-Anonymise-Then-Purge-Account-Deletion.md)

## References
- [Spec: audit-log](../specs/audit-log.md)
- SDGP §3.3 Auditability
