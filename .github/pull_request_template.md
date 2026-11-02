## Summary

<!-- What changes, and why. One or two sentences a reviewer can check the diff against. -->

## Spec and ADRs

<!-- Link the approved spec in docs/specs/ and any ADRs in docs/adr/. If none apply, say why (for example "bug fix, no behaviour change"). -->

- Spec:
- ADRs:
- Deviations (docs/deviations/), if any:

## Type of change

- [ ] Feature
- [ ] Bug fix
- [ ] Refactor (no behaviour change)
- [ ] Documentation / governance
- [ ] Dependency or infrastructure

## Testing

<!-- What you ran and what it covers. New behaviour comes with a test. -->

- [ ] `php artisan test --compact`
- [ ] `vendor/bin/pint --dirty`
- [ ] `npm run typecheck`
- [ ] `npm run build`

## Risk

<!-- What could go wrong in production, who is affected, and how you would notice. Call out migrations, money, personal data, permissions and security boundaries. -->

## How to revert

<!-- The exact steps to undo this safely: revert the commit, roll back migrations (are they reversible?), feature flags or settings to switch off. -->

## Checklist

- [ ] `CHANGELOG.md` updated under `## Unreleased` (or not needed: …)
- [ ] Admin and sensitive routes check a permission
- [ ] No secrets or personal data in code, logs or the audit log
- [ ] Money uses integer minor units (`App\Support\Money`)
- [ ] New personal data is covered by data export and account deletion

## AI agent involvement

<!-- Which agents (Claude Code, Copilot, Cursor, …) wrote or changed code or documents in this PR, and what a human reviewed. Commits carry a Co-Authored-By line for the agent. -->
