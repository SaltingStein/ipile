# Feature Specification: Account Deletion and Personal-Data Export

**Status**: Implemented (approved by review of the pull request that introduces it)  
**Author**: Ìpìlẹ̀ maintainers, drafted with Claude Code  
**Date**: 2026-11-02  
**Last Updated**: 2026-11-02

---

## Overview
People could not delete their account or download the personal data held about them; only consent settings existed. NDPR and GDPR give data subjects the rights of access (and portability) and erasure, and the SDGP requires products to honour them. Every product built on Ìpìlẹ̀ inherits these flows instead of building its own.

---

## User Flow
1. The person opens Settings → Security → Your data and confirms their password.
2. **Export:** they choose "Download my data" and receive `my-data-YYYY-MM-DD.json`.
3. **Delete:** they type `DELETE` and confirm.
4. If they are the last super admin, or own a team that has other members, deletion is refused with a message saying what to do first.
5. Otherwise: solo teams are deleted, sign-in methods are revoked, personal fields are anonymised, the account is soft deleted, all sessions are signed out, and they are redirected to the home page.
6. After the retention window, `model:prune` purges the anonymised row; payments remain as financial records with `user_id` set to null.

Mobile apps do the same through `GET /api/v1/auth/user/export` and `DELETE /api/v1/auth/user`.

---

## Scope

### In Scope
- `PersonalDataExport`: account fields, roles, teams, owned teams, connected accounts, payments and form responses; no secrets.
- `AccountDeletion`: guards, anonymisation, revocation, soft delete, session revocation, audit entry.
- Web routes behind password confirmation and the API routes, both rate-limited with named limiters (`account-export`, `account-deletion`, 5 per minute per user).
- The "Your data" panel on the Security settings page.

### Out of Scope
- Admin-initiated deletion on someone's behalf.
- A grace period during which the person can cancel the deletion.
- Exports in formats other than JSON.

---

## Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-01 | A signed-in person can download their personal data as JSON after confirming their password. | Must |
| FR-02 | The export contains no secrets (password hash, two-factor secret, recovery codes, tokens). | Must |
| FR-03 | Deletion requires recent password confirmation and the typed word `DELETE`. | Must |
| FR-04 | Deletion is refused for the last super admin and for owners of teams with other members. | Must |
| FR-05 | Deletion revokes API tokens, social accounts, trusted devices, magic links, SMS messages, pending invitations, team memberships, roles and all sessions. | Must |
| FR-06 | Deletion anonymises name, email, phone, avatar, password and two-factor fields immediately. | Must |
| FR-07 | Payments are kept, without personal details, and are never purged. | Must |
| FR-08 | Deletion and export are recorded in the audit log without personal data. | Must |
| FR-09 | The anonymised account is purged after the retention window. | Must |

---

## Non-Functional Requirements

| Category | Requirement |
|----------|-------------|
| Performance | Export and deletion run in the request; data per person is small. |
| Reliability | Anonymisation and revocation run in one transaction. |
| Security | Password confirmation, typed confirmation, rate limits, session revocation. |
| Compliance | NDPR/GDPR access, portability and erasure; financial record retention. |

---

## Data and State Changes

| Entity | Operation | Description |
|--------|-----------|-------------|
| `users` | UPDATE, DELETE | Anonymise, then soft delete; purged after retention. |
| `personal_access_tokens`, `social_accounts`, `trusted_devices`, `magic_links`, `sms_messages`, `team_invitations` | DELETE | Removed on deletion. |
| `team_user`, `model_has_roles` | DELETE | Memberships and roles detached. |
| `teams` | DELETE | Teams the person owns alone. |
| `payments` | READ | Exported; kept on deletion. |
| `audit_logs` | CREATE | `account.deleted`, `account.data_exported`. |

---

## Related ADRs

- [ADR-20261102-Anonymise-Then-Purge-Account-Deletion](../adr/ADR-20261102-Anonymise-Then-Purge-Account-Deletion.md)
- [ADR-20261102-Append-Only-Audit-Log](../adr/ADR-20261102-Append-Only-Audit-Log.md)

---

## API Endpoints (if applicable)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/settings/account/export` | Web: JSON download (password confirmation, `throttle:account-export`). |
| DELETE | `/settings/account` | Web: delete the account (password confirmation, `throttle:account-deletion`). |
| GET | `/api/v1/auth/user/export` | API: `{ data: { … } }`. |
| DELETE | `/api/v1/auth/user` | API: 204 on success, 422 without `confirm=DELETE`, 409 with a message when a guard refuses. |

---

## Risks and Edge Cases

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| A product adds personal data that the export and deletion miss | Medium | High | Non-negotiable 8, Sentinel repository rule, and the PR checklist. |
| Deleting the last super admin locks operators out | Low | High | Guard refuses; tested. |
| Deleting an owner orphans a team other people use | Low | High | Guard refuses until ownership is transferred; tested. |
| An attacker with a hijacked session deletes the account | Low | High | Password confirmation, typed confirmation and rate limit. |

---

## Rollback Plan
1. Revert the commit; no migration is involved. Accounts already deleted stay anonymised.

---

## Dependencies
- [Soft deletes with retention](soft-deletes-retention.md) for the purge.
- [Audit log](audit-log.md) for the audit entries.

---

## Testing Strategy

- Unit tests: none.
- Integration tests: `tests/Feature/Settings/AccountControllerTest.php` (anonymisation and sign-out, typed and password confirmation, revocation, payments kept, team and super-admin guards, audit without personal data, purge after retention, export contents and secrets, API export and deletion, API guard message).
- E2E tests: none.
