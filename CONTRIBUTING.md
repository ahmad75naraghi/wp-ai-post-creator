# Contributing to AI Post Creator

Thanks for helping! This plugin ships to real WordPress sites, so the bar is:
**works on PHP 7.4, no runtime dependencies, fully translated, e2e-verified.**

> **خلاصهٔ فارسی:** برای مشارکت، چهار بررسی زیر باید سبز باشد (لینت PHP،
> بررسی JS، ترجمه‌ها و مجموعهٔ تست e2e)؛ رشته‌های جدید کاربر-رو باید در همان
> PR ترجمهٔ فارسی داشته باشند و CI روی هر PR اجرا می‌شود و تا سبز نشود مرج
> نمی‌شود. برای قواعد روزمرهٔ توسعه [`AGENTS.md`](AGENTS.md) و برای نقشهٔ
> کلان سیستم [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) را ببینید.
> باگ‌ها را با قالب issue گزارش کنید ([SUPPORT.md](SUPPORT.md)).

Read [`AGENTS.md`](AGENTS.md) for the day-to-day working rules (they apply to
human contributors just as much as to AI agents) and
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for how the system fits together.
[`docs/COOKBOOK.md`](docs/COOKBOOK.md) has step-by-step recipes for most common
changes, and [`docs/DEBUGGING.md`](docs/DEBUGGING.md) explains how to
investigate problems.

## Requirements

- Git, **Node.js ≥ 22** (the e2e runner needs it — under Node 20 the php-wasm
  host-filesystem layer misbehaves), Python 3 (translation tooling)
- A WordPress 5.7+ / PHP 7.4+ environment for manual testing (the automated
  suite bundles its own WordPress — see below)
- No PHP CLI is required for the automated checks — php-wasm runs PHP 8.3 in Node

## Getting started

```bash
git clone https://github.com/ahmad75naraghi/wp-ai-post-creator.git
cd wp-ai-post-creator
```

There is no build step: the plugin is plain PHP/JS/CSS. Symlink or copy the repo
folder into `wp-content/plugins/` of a development WordPress install.

## Before you open a PR

Run all four checks — **CI (`.github/workflows/ci.yml`) runs exactly these on
every push and pull request, and the PR cannot merge while it is red**:

```bash
# 1) PHP syntax (PHP 7.4 target)
node tests/e2e/lint.js

# 2) JS syntax
node --check assets/admin-agent.js && node --check assets/admin-connections.js && node --check assets/admin-schedule.js

# 3) Translations complete (exit 0 = nothing missing)
python3 tests/e2e/make-translations.py

# 4) End-to-end suite (real WordPress 6.7.1 + SQLite via php-wasm)
#    Set up the workspace once — the full recipe is in tests/e2e/README.md:
#      mkdir -p ~/wp-e2e && cd ~/wp-e2e
#      npm init -y && npm i php-parser@3 @php-wasm/universal @php-wasm/node
#      curl -sL -o wp.tar.gz  "https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/6.7.1"
#      tar -xzf wp.tar.gz && mv WordPress-6.7.1 wordpress
#      curl -sL -o sql.tar.gz "https://codeload.github.com/aaemnnosttv/wp-sqlite-db/tar.gz/refs/heads/master"
#      tar -xzf sql.tar.gz && cp wp-sqlite-db-master/src/db.php wordpress/wp-content/db.php
#      mkdir -p wordpress/wp-content/mu-plugins wordpress/wp-content/plugins
#    then, for every run — always recopy the plugin and the mock:
#      cp -r <repo> wordpress/wp-content/plugins/wp-ai-post-creator
#      cp <repo>/tests/e2e/{e2e.js,install.php,drive.php} .
#      cp <repo>/tests/e2e/mock-api.php wordpress/wp-content/mu-plugins/
#      node e2e.js
```

The e2e suite must stay **green with zero `false` boolean leaves and zero PHP
warnings** (45 result groups / 401 assertions at v1.6.0). If your change alters
behavior covered by `tests/e2e/drive.php`, update the assertions in the same PR.

## Coding standards

- Follow the existing style: tabs, WordPress coding standards flavor, Yoda
  conditions where the codebase uses them.
- Prefix everything: classes `AIPC_*`, options/hooks/filters `aipc_*`, meta keys
  `_aipc_*`, text domain `wp-ai-post-creator`.
- PHP 7.4 syntax only. No Composer runtime dependencies, no build step.
- Escape on output (`esc_html__`, `esc_attr`, `esc_url`, `wp_kses_post`) and
  sanitize on input (the existing `sanitize()`/`sanitize_entry()` methods).
- New prompts belong in `AIPC_Steps::registry()` with a `placeholders` doc array
  — never inline in the agent.
- New user-facing strings must be translated *in the same PR* (Persian
  translations go into `tests/e2e/make-translations.py` → `NEW_TRANSLATIONS`).
- New user-facing behavior needs e2e assertions *in the same PR*
  (`tests/e2e/README.md` → "Conventions when extending").

## Commit / PR conventions

- Commit title: `vX.Y.Z — short summary` for releases; otherwise
  `component: what changed` (e.g. `agent: per-connection retry budget`).
- One logical change per PR, with the motivation in the description (the PR
  template carries the verification checklist).
- PRs target `main` directly. Keep history clean; CI must be green before
  review.

## Reporting bugs & feature requests

Open a GitHub issue using the templates (`.github/ISSUE_TEMPLATE/`) — include
WordPress version, PHP version, plugin version, the exact step where it failed,
and the job log (AI Post Creator → Logs → the job's detail page). API keys must
never appear in the log — if they do, report it as a
[security issue](https://github.com/ahmad75naraghi/wp-ai-post-creator/security/advisories/new)
instead. See [SUPPORT.md](SUPPORT.md) for the full help map.
