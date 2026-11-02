# ADR-20261102-Anonymise-Then-Purge-Account-Deletion

**Status**: Proposed (Accepted when the introducing pull request is approved)  
**Date**: 2026-11-02  
**Decision Owner**: Ìpìlẹ̀ maintainers  
**Approved By**: Pull request reviewer

---

## Context
People must be able to erase their account (NDPR/GDPR right to erasure), and identity and personal data are ADR triggers. A user row is referenced by payments (which must be kept as financial records), audit entries, teams, tokens, social accounts and more. Deleting the row outright would either cascade into records we must keep or fail on foreign keys; keeping it intact would retain personal data. The SDGP also requires soft deletes by default. Spec: [account-deletion-and-export](../specs/account-deletion-and-export.md).

---

## Decision
When a person deletes their account, in one transaction: revoke every sign-in method and membership, overwrite personal fields with anonymous values (name "Deleted user"; email, phone, avatar, password and two-factor fields null), record an audit entry, and soft delete the row. Then sign out every session. `model:prune` permanently removes the anonymised row after `retention.deleted_records_days`; payments keep their row with `user_id` set to null.

---

## Options Considered

### Option 1: Anonymise immediately, soft delete, purge after retention (chosen)
- **Pros**: Personal data is gone at once; references stay valid during the window; consistent with soft deletes everywhere else; financial records survive.
- **Cons**: An empty user row exists for up to the retention window.

### Option 2: Hard delete immediately
- **Pros**: Simplest; nothing remains.
- **Cons**: Cascades into or orphans payments and audit references; no window to investigate abuse; contradicts the soft-delete default.

### Option 3: Soft delete only, keep personal data until purge
- **Pros**: Fully restorable.
- **Cons**: Personal data is retained after the person asked for erasure; a breach in the window exposes it.

---

## Rationale
Option 1 honours erasure immediately while keeping the database consistent and the financial record intact, and it reuses the retention mechanism every other record uses.

---

## Consequences

### Positive
- Erasure takes effect at once; the export and deletion are each one request.
- Payments and the audit trail remain accountable without personal data.

### Negative
- Deletion cannot be undone; restoring the row brings back an anonymous account only.

### Neutral
- The last super admin and owners of shared teams must hand over first.

---

## Security and Risk Notes
- Requires recent password confirmation, the typed word `DELETE`, and a named rate limiter; all sessions and tokens are revoked.
- The audit entry records the action and the account id, never the personal values.
- New personal data added by a product must be added to `AccountDeletion` and `PersonalDataExport` (non-negotiable 8).

---

## Review Triggers
- A legal requirement to keep personal data for a period (for example KYC records).
- A requirement for a cancellable grace period or admin-initiated erasure.

---

## ISO 27001 Mapping
Which controls does this ADR support?

- [x] A.8 - Change Management
- [x] A.5 - Risk Assessment
- [x] A.8.25 - Secure Development
- [x] A.5.15 - Access Control
- [ ] A.5.29 - Business Continuity
- [ ] A.5.37 - Documented Procedures

---

## Related ADRs
- [ADR-20261102-Append-Only-Audit-Log](ADR-20261102-Append-Only-Audit-Log.md)

## References
- [Spec: account-deletion-and-export](../specs/account-deletion-and-export.md)
- [Spec: soft-deletes-retention](../specs/soft-deletes-retention.md)
- NDPR; GDPR Articles 15, 17 and 20
