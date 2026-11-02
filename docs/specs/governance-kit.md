# Feature Specification: SDGP Governance Kit

**Status**: Implemented (approved by review of the pull request that introduces it)  
**Author**: Ìpìlẹ̀ maintainers, drafted with Claude Code  
**Date**: 2026-11-02  
**Last Updated**: 2026-11-02

---

## Overview
Ìpìlẹ̀ is the starting point for the company's Laravel applications, but a project created from it started outside the governance process: no place for specs, ADRs or deviations, no PR template, no instructions telling AI agents about the SDGP hierarchy, and no Sentinel review rules. Each team had to add these by hand, and most would not. Putting the kit in the template means every project starts governed.

---

## User Flow
1. An engineer creates a repository from Ìpìlẹ̀ with **Use this template**.
2. The repository already contains the governance folders and templates, the PR template, agent instructions for Claude Code, Codex, Copilot and Cursor, and a Sentinel rules file.
3. They rename `.ipile.md` to `.<repo-name>.md` and add their BRD, PRD and architecture.
4. Their AI agents refuse to skip the hierarchy without saying so, flag ADR triggers, and log deviations.
5. Every pull request asks for the spec, testing, risk and how to revert.

---

## Scope

### In Scope
- `.ai/guidelines/sdgp.md` (merged into `CLAUDE.md` and `AGENTS.md` by Laravel Boost).
- New non-negotiables 5–9 in `.ai/guidelines/ipile.md` and the developer guide (money, soft deletes, audit, personal data, rate limiters).
- `.github/copilot-instructions.md` and `.cursorrules` pointing at `AGENTS.md`.
- `.github/pull_request_template.md`.
- `docs/specs/`, `docs/adr/`, `docs/deviations/` with templates; `dev-project-docs/`; `docs/solution-doc-architecture.md`.
- `.ipile.md` Sentinel repository rules.
- A "How work is planned and reviewed" section in the developer guide.

### Out of Scope
- Copying the full SDGP into this repository. The repository is public; the policy is referenced, and the parts agents need are summarised in `.ai/guidelines/sdgp.md`.
- CI enforcement of the PR template or of spec links.
- Coverage measurement (planned follow-up).

---

## Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-01 | Agents reading `CLAUDE.md` or `AGENTS.md` are told the hierarchy, ADR triggers, document locations and deviation process. | Must |
| FR-02 | Copilot and Cursor are pointed at the same instructions. | Must |
| FR-03 | Pull requests prompt for spec and ADR links, testing, risk, how to revert and AI involvement. | Must |
| FR-04 | Spec, ADR and deviation templates are present at the documented paths. | Must |
| FR-05 | A Sentinel repository-rules file encodes the Ìpìlẹ̀ non-negotiables. | Should |
| FR-06 | The hardening changes in this release have specs and ADRs in the new folders. | Must |

---

## Non-Functional Requirements

| Category | Requirement |
|----------|-------------|
| Performance | Not applicable. |
| Reliability | `php artisan boost:update` regenerates `CLAUDE.md` and `AGENTS.md` from `.ai/guidelines`, so the governance section survives updates. |
| Security | No internal policy text, credentials or internal URLs in the public repository. |
| Compliance | SDGP §7 and §8 (hierarchy, documentation, PR standards). |

---

## Data and State Changes

None. Documentation and repository configuration only.

---

## Related ADRs

None. No ADR trigger applies: the kit adds documents and instructions only.

---

## API Endpoints (if applicable)

None.

---

## Risks and Edge Cases

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| `CLAUDE.md`/`AGENTS.md` edited by hand drift from `.ai/guidelines` | Low | Low | The sections are byte-for-byte what Boost generates from `.ai/guidelines`; the next `boost:update` reproduces them. |
| Projects keep `.ipile.md` without renaming it | Medium | Low | Step 2 of "Starting a new project" in the developer guide. |
| Engineers treat the templates as optional | Medium | Medium | Agents flag skipped steps and log deviations; Sentinel flags missing ADRs. |

---

## Rollback Plan
1. Revert the commit. No code, data or migrations are affected.

---

## Dependencies
- Laravel Boost (existing) for merging `.ai/guidelines` into agent instruction files.
- Sentinel for reading `.<repo-name>.md`.

---

## Testing Strategy

- Unit tests: none (documentation).
- Integration tests: the full suite, type-check and build are run to confirm nothing else changed.
- E2E tests: none.
