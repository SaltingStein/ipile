# Governance (SDGP)

Projects built on Ìpìlẹ̀ follow the company's Software Development Governance Policy (SDGP). The policy itself is kept in the company's internal governance repository; this section is the part an agent needs while working here.

## The development hierarchy

Work follows this order: BRD → PRD → Solution document → Feature spec → ADR → Implementation → Testing → Deployment.

- Do not write implementation code for a feature that has no approved spec in `docs/specs/`. Do not write an ADR without a parent spec.
- If the developer asks to skip a step, say which step is missing and why it matters, offer to write it, and go ahead only if they confirm. Then add an entry to `docs/deviations/` (template: `docs/deviations/_template.md`).
- When starting a new project from Ìpìlẹ̀, check `dev-project-docs/` (BRD, PRD) and `docs/solution-doc-architecture.md`. If they are still placeholders, tell the developer before writing feature specs.

## Where governance documents go

| Document | Location | Template |
|---|---|---|
| BRD, PRD | `dev-project-docs/` | |
| Solution / architecture document | `docs/solution-doc-architecture.md` | |
| Feature specs | `docs/specs/<feature-name>.md` | `docs/specs/_template.md` |
| ADRs | `docs/adr/ADR-YYYYMMDD-Short-Title.md` | `docs/adr/_template.md` |
| Deviations | `docs/deviations/DEV-YYYYMMDD-NNN-short-title.md` | `docs/deviations/_template.md` |

Use the templates as they are; do not invent other formats.

## When an ADR is required

Flag it before implementation when a change: moves money; touches identity or personal data; changes architecture or data ownership; adds a cross-service integration; changes retry, concurrency or idempotency logic; changes infrastructure or deployment; changes a security boundary; adds an external dependency; selects or changes an ML model or scoring algorithm; or makes a workaround permanent.

## Pull requests and history

- Work on a feature branch and open a pull request; never push to `main`. Use conventional commits (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`).
- Fill in every section of `.github/pull_request_template.md`, including Testing, Risk and How to revert, and note any AI agent involvement.
- Record user-visible changes in `CHANGELOG.md` under `## Unreleased`.
- When an agent writes or substantially changes code or governance documents, add a `Co-Authored-By` line naming the agent to the commit.

## Other agents' instruction files

`.github/copilot-instructions.md` and `.cursorrules` point other tools at this file. Read them at the start of a session; if a developer has added notes there that are not reflected here, mention them and ask whether to follow them.
