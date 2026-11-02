# Feature Specification: Soft Deletes with a Retention Window

**Status**: Implemented (approved by review of the pull request that introduces it)  
**Author**: Ìpìlẹ̀ maintainers, drafted with Claude Code  
**Date**: 2026-11-02  
**Last Updated**: 2026-11-02

---

## Overview
No model used soft deletes, so any delete was immediate and permanent: a mistaken delete could not be undone and there was no window to investigate. The SDGP requires soft deletes by default. Keeping deleted records forever would conflict with data-minimisation duties (NDPR/GDPR), so deleted records are kept for a fixed window and then purged.

---

## User Flow
1. A person or admin deletes a record (a blog post, a form, a campaign page, a form response, an account).
2. The record is soft deleted: it disappears from the app and its routes, but its row and files remain.
3. Within the retention window an admin or developer can restore it (`restore()`), and it comes back with its files.
4. Nightly, `model:prune` permanently removes records deleted more than `retention.deleted_records_days` ago, and deletes their files.

---

## Scope

### In Scope
- `SoftDeletesWithRetention` trait (SoftDeletes + Prunable) and `config/retention.php` (`deleted_records_days`, default 30).
- Soft deletes on `users`, `blog_posts`, `campaign_pages`, `forms`, `form_submissions` (purged after retention) and `payments` (never purged).
- File clean-up moved from controllers to each model's `pruning()` method.
- Asset pruners (`BlogAssets`, `CampaignAssets`) treat files used by soft-deleted records as still in use.
- `model:prune` scheduled daily at 02:55.

### Out of Scope
- A restore UI in the admin area (restore is available in code and Tinker).
- `teams` and `blog_categories`, which rely on database cascades; soft deleting them would leave cascaded rows inconsistent.

---

## Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-01 | Deleting a covered record soft deletes it; it is hidden from queries and route model binding. | Must |
| FR-02 | Soft-deleted records are purged after `retention.deleted_records_days`, not before. | Must |
| FR-03 | Files belonging to a record are deleted only when the record is purged. | Must |
| FR-04 | The nightly orphan-image clean-up does not delete files used by a soft-deleted record. | Must |
| FR-05 | Payments are soft deleted but never purged (financial records). | Must |
| FR-06 | The retention window is configurable. | Should |

---

## Non-Functional Requirements

| Category | Requirement |
|----------|-------------|
| Performance | `deleted_at` is indexed on every soft-deleting table. |
| Reliability | Purging runs in the scheduler; a missed night is caught up the next night. |
| Security | Soft-deleted users cannot sign in (they are excluded from queries). |
| Compliance | SDGP soft deletes by default; NDPR/GDPR storage limitation via the purge. |

---

## Data and State Changes

| Entity | Operation | Description |
|--------|-----------|-------------|
| `users`, `payments`, `blog_posts`, `campaign_pages`, `forms`, `form_submissions` | UPDATE (schema) | Add indexed `deleted_at`. |
| Covered models | DELETE | Soft delete; purge after retention (except payments). |
| Stored files | DELETE | Removed in `pruning()` when the owning record is purged. |

---

## Related ADRs

- [ADR-20261102-Anonymise-Then-Purge-Account-Deletion](../adr/ADR-20261102-Anonymise-Then-Purge-Account-Deletion.md) (the same retention window applies to deleted accounts)

---

## API Endpoints (if applicable)

None changed. Existing delete endpoints now soft delete.

---

## Risks and Edge Cases

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Unique columns (slugs, emails) collide with a soft-deleted row | Medium | Low | Deleted accounts have their email cleared on deletion; content slugs stay reserved until purge, which is acceptable for 30 days. |
| Orphan-file pruners delete a restorable record's files | Medium | High | Pruners check `withTrashed()`; covered by a test that fails without the fix. |
| Product code relies on hard deletes | Low | Medium | Tests rewritten to the soft-delete-then-purge contract; `purgeDeletedRecords()` test helper. |

---

## Rollback Plan
1. Revert the commit.
2. Roll back `2026_11_02_110000_add_soft_deletes`; this drops `deleted_at` (soft-deleted rows become visible again, so purge or hard delete them first if that matters).

---

## Dependencies
- The Laravel scheduler running in production (`php artisan schedule:run` every minute).

---

## Testing Strategy

- Unit tests: none.
- Integration tests: `tests/Feature/SoftDeleteRetentionTest.php` (retention boundary, restore with files, pruner safety, payments never purged, hidden from queries) and the rewritten blog, campaign and form tests.
- E2E tests: none.
