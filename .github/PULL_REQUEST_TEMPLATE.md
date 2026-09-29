<!--
  Notes before you fill this in:
  - One logical change per PR, motivation in the description.
  - The verification suite (below) must be green — CI runs exactly these checks
    on every PR and blocks the merge while red.
  - New user-facing strings need Persian translations in the SAME PR.
-->

## What & why

<!-- What changes, and why — link the issue if there is one (`Closes #N`). -->

## Type of change

- [ ] Bug fix (non-breaking)
- [ ] New feature (non-breaking)
- [ ] Breaking change
- [ ] Docs / tooling only

## Verification (all four must pass)

- [ ] `node tests/e2e/lint.js` → 0 failed (PHP 7.4 target)
- [ ] `node --check assets/admin-agent.js && node --check assets/admin-connections.js && node --check assets/admin-schedule.js`
- [ ] `python3 tests/e2e/make-translations.py` → exit 0, no MISSING list
- [ ] Full e2e (`cd <e2e-workspace> && node e2e.js`) → **zero `false` leaves, zero PHP warnings** — see `tests/e2e/README.md` for the workspace recipe (Node 22+)

## Checklist

- [ ] PHP 7.4-only syntax; no new runtime dependencies, no build step
- [ ] Prefixes respected (`AIPC_*` classes, `aipc_*` options/hooks/filters, `_aipc_*` meta)
- [ ] Input sanitized / output escaped; API keys never leave the server
- [ ] New user-facing strings translated (fa_IR) in this PR
- [ ] e2e assertions updated in this PR if behavior changed
- [ ] Docs updated (`docs/`, `CHANGELOG.md`, `readme.txt` when user-facing)
- [ ] UI changes: screenshot before/after attached
