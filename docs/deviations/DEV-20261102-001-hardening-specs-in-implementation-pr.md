### DEV-20261102-001

- **Date:** 2026-11-02
- **Developer:** Ìpìlẹ̀ maintainers (requested by the repository owner; committed as `pseudoadmin`)
- **AI Agent:** Claude Code (Claude Opus 5.5)
- **Rule Deviated From:** Rule 1: Respect the Hierarchy (spec and ADRs approved before implementation)
- **What Was Skipped:** The five SDGP hardening changes (integer money, soft deletes with retention, audit log, account deletion and export, governance kit) were implemented before their specs and ADRs were approved. The specs and ADRs are submitted for approval in the same pull request as the code. Ìpìlẹ̀ itself has no BRD or PRD; its README, developer guide and solution document stand in for them.
- **Justification Given:** The gaps were identified in a review of Ìpìlẹ̀ against the SDGP, and the owner asked for the fixes and their specs to be raised together so the boilerplate can be adopted as the company standard without a separate approval cycle. The governance folders did not exist in this repository before this change.
- **Risk Assessment:** Low to medium. Reviewers approve the design and the code at the same time, so a design objection means reworking code rather than a document. Mitigated by keeping each change in its own commit with its own spec, ADR and tests, so any one can be reverted independently.
- **Resolution:** Specs and ADRs are reviewed in the same pull request; merging the pull request approves them. Future changes to Ìpìlẹ̀ follow the hierarchy in order.
