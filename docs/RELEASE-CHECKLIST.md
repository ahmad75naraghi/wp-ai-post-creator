# Release Checklist

How to cut a release of AI Post Creator. Current version target: see the
`AIPC_VERSION` constant in `wp-ai-post-creator.php`.

## 1. Code freezes

- [ ] All planned features merged on the working branch.
- [ ] `node tests/e2e/lint.js` → 0 failed.
- [ ] `node --check assets/*.js` → clean.
- [ ] `python3 tests/e2e/make-translations.py` → exit 0 (no MISSING list).
- [ ] Full e2e run green: **no `false` boolean leaf** in the `===E2E_JSON===`
      output and **zero PHP warnings/deprecations** in the log.
      (Recipe and workspace setup: `tests/e2e/README.md`.)

## 2. Version bump

- [ ] `wp-ai-post-creator.php`: `define( 'AIPC_VERSION', 'X.Y.Z' )` **and** the
      ` * Version:` plugin header line (both must match).
- [ ] `readme.txt`: `Stable tag: X.Y.Z` + a new `= X.Y.Z =` changelog section.
- [ ] `README.md`: the `**Version:**` line + new "New in X.Y.Z" blocks
      (Persian **and** English sections).
- [ ] `CHANGELOG.md`: a `[X.Y.Z] — YYYY-MM-DD` entry (Added / Changed / Verified).

## 3. Translations

- [ ] Re-run `make-translations.py` **after** the last string change; commit the
      regenerated `.po`, `.pot` **and binary `.mo`** together.
- [ ] The e2e `i18n_fa` group must be green (it loads the real `.mo` and checks
      old + new strings and plural forms round-trip).

## 4. Commit & push

- [ ] Commit as `Arena Agent <agent@arena.ai>` with title `vX.Y.Z — summary`.
- [ ] Push the working branch only (while PR #1 is open that is
      `arena/01a0de38-wp-ai-post-creator`; never push `main` directly).

## 5. Pull request

- [ ] Update PR #1 title/body. **`gh pr edit` is broken** (GraphQL
      "Projects (classic)" error) — use REST:
      ```bash
      python3 -c "import json; json.dump({'title':'…','body':'…'}, open('/tmp/pr.json','w'))"
      gh api -X PATCH repos/ahmad75naraghi/wp-ai-post-creator/pulls/1 --input /tmp/pr.json
      ```
- [ ] Body must list the new version's features at the top and the current
      e2e assertion count.
- [ ] Wait ~5 s, then confirm: `gh pr view 1 --json mergeable,mergeStateStatus`
      → `MERGEABLE / CLEAN`.

## 6. After merge (when the maintainer merges PR #1)

- [ ] Tag the merge commit: ` vX.Y.Z` (annotated).
- [ ] Build the release zip: `git archive` of the plugin folder excluding
      `tests/`, `.git`, `*.md` (keep `readme.txt`) → attach to the GitHub
      release.
- [ ] If publishing to wp.org: `readme.txt` is the canonical metadata —
      validate it (stable tag, tested-up-to) with the plugin-check action.
