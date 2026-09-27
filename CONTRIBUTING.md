# Contributing to AI Post Creator

Thanks for helping! This plugin ships to real WordPress sites, so the bar is:
**works on PHP 7.4, no runtime dependencies, fully translated, e2e-verified.**

Read [`AGENTS.md`](AGENTS.md) for the day-to-day working rules (it applies to
human contributors just as much as to AI agents) and
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for how the system fits together.

## Requirements

- Git, Node.js ≥ 18 (test runner only), Python 3 (translation tooling)
- A WordPress 5.7+ / PHP 7.4+ environment for manual testing (the automated suite
  bundles its own WordPress — see below)
- No PHP CLI is required for the automated checks

## Getting started

```bash
git clone https://github.com/ahmad75naraghi/wp-ai-post-creator.git
cd wp-ai-post-creator
```

There is no build step: the plugin is plain PHP/JS/CSS. Symlink or copy the repo
folder into `wp-content/plugins/` of a development WordPress install.

## Before you open a PR

Run all four checks — the CI of the future will run exactly these:

```bash
# 1) PHP syntax (PHP 7.4 target)
node tests/e2e/lint.js

# 2) JS syntax
node --check assets/admin-agent.js && node --check assets/admin-connections.js && node --check assets/admin-schedule.js

# 3) Translations complete (exit 0 = nothing missing)
python3 tests/e2e/make-translations.py

# 4) End-to-end suite (real WordPress + SQLite via php-wasm)
#    Set up the workspace once — see tests/e2e/README.md for the full recipe
```

The e2e suite must stay **green with zero PHP warnings**. If your change alters
behavior covered by `tests/e2e/drive.php`, update the assertions in the same PR
and keep the boolean-leaves-false count at zero.

## Coding standards

- Follow the existing style: tabs, WordPress coding standards flavor, Yoda
  conditions where the codebase uses them.
- Prefix everything: classes `AIPC_*`, options/hooks/filters `aipc_*`, meta keys
  `_aipc_*`, text domain `wp-ai-post-creator`.
- PHP 7.4 syntax only. No Composer runtime dependencies.
- Escape on output (`esc_html__`, `esc_attr`, `esc_url`, `wp_kses_post`) and
  sanitize on input (the existing `sanitize()`/`sanitize_entry()` methods).
- New prompts belong in `AIPC_Steps::registry()` with a `placeholders` doc array
  — never inline in the agent.
- New user-facing strings must be translated *in the same PR* (Persian
  translations go into `tests/e2e/make-translations.py` → `NEW_TRANSLATIONS`).

## Commit / PR conventions

- Commit title: `vX.Y.Z — short summary` for releases; otherwise
  `component: what changed` (e.g. `agent: per-connection retry budget`).
- One logical change per PR, with the motivation in the description.
- PRs are merged into the integration branch referenced by PR #1 while it is
  open; `main` moves only via that PR.

## Reporting bugs

Open a GitHub issue with: WordPress version, PHP version, plugin version, the
exact step where it failed, and the job log (AI Post Creator → Logs → the job's
detail page). API keys must never appear in the log — if they do, report it as a
security issue instead (see [SECURITY.md](SECURITY.md)).
