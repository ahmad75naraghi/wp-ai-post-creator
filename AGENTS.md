# AGENTS.md — Working Guide for AI Agents (and humans in a hurry)

This file tells you how to work on **AI Post Creator** safely. Read it fully before
touching code. Companion documents:

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — full system reference (data model, execution loop, hooks)
- [`docs/COOKBOOK.md`](docs/COOKBOOK.md) — step-by-step recipes for every common change (add a step, a setting, a page, a REST route, translations…)
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — what's next + binding product decisions (do not violate the decision log)
- [`docs/REST-API.md`](docs/REST-API.md) / [`docs/DEBUGGING.md`](docs/DEBUGGING.md) — API surface and troubleshooting
- [`tests/e2e/README.md`](tests/e2e/README.md) — the test world and how to extend it

## What this project is

A WordPress plugin (v1.5.0) that turns the admin into an AI content agent: any
OpenAI-compatible API connection, a 13-step pipeline (new-post and rewrite modes),
per-step connection **fallback chains**, internal linking, research source sites
(RSS), scheduled auto-publishing, Bale messenger notifications, and a full e2e
test suite that runs real WordPress. Persian (fa_IR) is a first-class language —
every user-facing string is translated.

Current state: all feature work lives on the branch `arena/01a0de38-wp-ai-post-creator`
and is tracked by **PR #1** (open, against `main`). Do not push to `main`.

## Golden rules

1. **Never claim something works without running the verification suite** (below).
2. **Never break the e2e suite** — it is the project's contract (141/141 assertion
   groups green at v1.5.0, zero PHP warnings).
3. **PHP 7.4 compatible** syntax only (the linter parses with `version: 704`).
   No enums, no readonly, no match expressions, no named args.
4. **No build step, no runtime dependencies.** Plain PHP + jQuery-free vanilla JS.
   Composer/npm packages are dev/test-only.
5. **Every new user-facing string must be translated** (see Translation workflow).
   A run of `make-translations.py` must end in success, never a MISSING list.
6. **Drafts by default.** Publishing is always an explicit choice (`publish_mode`).
   Never reintroduce a default non-draft flow.
7. Follow the existing prefixes: classes `AIPC_*`, options/hooks/filters `aipc_*`,
   text domain `wp-ai-post-creator`, meta keys `_aipc_*`.

## Verification commands

```bash
# 1) Syntax lint — every plugin PHP file parsed as PHP 7.4
#    (php-parser must be installed somewhere reachable; see "E2E workspace" below)
node tests/e2e/lint.js

# 2) JS sanity
node --check assets/admin-agent.js

# 3) Translations — must print "0 new" / exit 0 (any MISSING aborts)
python3 tests/e2e/make-translations.py

# 4) Full e2e — real WordPress 6.7 on SQLite via php-wasm, mock AI/Bale/RSS providers
#    (needs the e2e workspace, see below)
cd <E2E_WORKSPACE> && node e2e.js
```

Run all four before every commit — CI (`.github/workflows/ci.yml`) runs the
same checks on GitHub runners for every push/PR and blocks merges while red.
The e2e phase-2 JSON must contain **no `false`**
boolean leaf — a helper to check is:

```bash
python3 - <<'PY'
import json,sys
src=open('/tmp/e2e.log',encoding='utf-8').read()
data=json.loads(src[src.index('{\n    "unit_extract_json"'):])
def flat(d,p=''):
    for k,v in d.items():
        yield from (flat(v,p+'.'+k) if isinstance(v,dict) else [(p+'.'+k,v)])
bad=[k for k,v in flat(data) if v is False]
print('FALSE:',bad); sys.exit(1 if bad else 0)
PY
```

## E2E workspace (outside the repo) — and why it keeps disappearing

The e2e workspace holds `node_modules`, a WordPress 6.7.1 install with the SQLite
drop-in, and copies of the test runner. It lives **outside** the repo (in the Arena
sandbox: `/home/user/.cache/e2e`) and **may be wiped between turns**. Rebuild:

```bash
mkdir -p ~/wp-e2e && cd ~/wp-e2e
npm init -y && npm i php-parser@3 @php-wasm/universal @php-wasm/node
curl -sL -o wp.tar.gz  "https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/6.7.1"
tar -xzf wp.tar.gz && mv WordPress-6.7.1 wordpress
curl -sL -o sql.tar.gz "https://codeload.github.com/aaemnnosttv/wp-sqlite-db/tar.gz/refs/heads/master"
tar -xzf sql.tar.gz && cp wp-sqlite-db-master/src/db.php wordpress/wp-content/db.php
mkdir -p wordpress/wp-content/mu-plugins wordpress/wp-content/plugins
```

Then, for every e2e run (the plugin and mock must be freshly copied in):

```bash
cd ~/wp-e2e
rm -rf wordpress/wp-content/plugins/wp-ai-post-creator
cp -r <REPO> wordpress/wp-content/plugins/ && rm -rf wordpress/wp-content/plugins/wp-ai-post-creator/.git
cp <REPO>/tests/e2e/{e2e.js,install.php,drive.php} .
cp <REPO>/tests/e2e/mock-api.php wordpress/wp-content/mu-plugins/
node e2e.js
```

`e2e.js` reads `E2E_WP_ROOT` (defaults to `./wordpress`), bootstraps WordPress once
for `install.php` (fresh DB, connections, schedule, Bale, source sites) and once
for `drive.php` (the assertions). Output: install JSON, then the `===E2E_JSON===`
block with all assertion groups.

## Environment gotchas (Arena sandbox)

- **The repo checkout resets between turns.** Uncommitted edits survive (working
  tree), but the branch head reverts. Recover with:
  `git fetch origin <branch-sha> && git reset --mixed <branch-sha>`
  (find the sha via `git ls-remote origin refs/heads/arena/01a0de38-wp-ai-post-creator`).
- **No PHP CLI**, apt is blocked, GitHub release binaries fail SSL.
  `codeload.github.com`, `api.github.com` and `registry.npmjs.org` work.
- **wp-sqlite-db (e2e drop-in) SQL quirks** — all verified the hard way:
  - `SELECT 1 FROM <missing table>` returns **int(1)**, not false — an
    existence probe must use `SHOW TABLES LIKE` (`AIPC_Job_Store::table_exists`).
  - `DROP TABLE` inside a php-wasm request is **silently ignored** — never rely
    on it in tests; wipe `wp-content/database/*` files instead (install.php does).
  - `dbDelta` on an **existing** table sees SQLite's type mapping (VARCHAR→TEXT)
    as a diff and issues `ALTER TABLE … CHANGE COLUMN`, which the drop-in fails
    with a `trim(null)` deprecation — so `ensure_table()` must only dbDelta when
    the table is missing, never to "upgrade" it.
- **`gh pr edit` fails** (GraphQL "Projects (classic)" error). Update PR #1 with
  REST instead: `gh api -X PATCH repos/ahmad75naraghi/wp-ai-post-creator/pulls/1
  --input <json with title/body>`.
- Commit as `Arena Agent <agent@arena.ai>`; commit title format `vX.Y.Z — summary`.
- PR mergeability is unknown for ~5s after a push — sleep, then re-query.

## Code map

| Path | Role |
|---|---|
| `wp-ai-post-creator.php` | Bootstrap: constants, requires, activation, cron hooks, admin-bar link |
| `includes/class-aipc-agent.php` | The state machine: job facade over `AIPC_Job_Store`, step manifests, chain-retry loop, all `step_*()` implementations, context helpers, stats |
| `includes/class-aipc-steps.php` | 13-step registry (prompts + kinds) and per-step `connections[]` config |
| `includes/class-aipc-post-builder.php` | Assembles/saves posts (`create()` new, `update()` rewrite), TOC/FAQ HTML, SEO meta, featured image |
| `includes/class-aipc-connections.php` | CRUD + sanitize for AI connections (write-only API keys) |
| `includes/class-aipc-api-client.php` | OpenAI-compatible HTTP client: chat (JSON extraction + corrective retries), images, models, downloads (guard-checked); one internal retry on 429/5xx |
| `includes/class-aipc-job-store.php` | Jobs storage: `{prefix}aipc_jobs` table (schema versioning, legacy-option migration + fallback), CRUD, light-row queries, retention pruning |
| `includes/class-aipc-network.php` | Outbound network guard (SSRF): `is_safe_url()`/`validate_url()`, private-range blocking, allowlist + loopback filters |
| `includes/class-aipc-scheduler.php` | Cron tick (every 15 min, safety net), schedule entries, daily limit, `aipc_publish_post` handler, background runner (`aipc_run_job`) |
| `includes/class-aipc-bale.php` | Bale Bot API: notify per post, publish notifications, periodic reports |
| `includes/class-aipc-settings.php` | Plugin settings incl. `site_prompt`, `source_sites`, defaults for tone/length/language |
| `includes/class-aipc-rest.php` | REST namespace `aipc/v1` (start/step/state/cancel/retry with per-user rate limits, connection, bale) |
| `includes/class-aipc-admin.php` | Menu, admin-post handlers, view rendering |
| `includes/class-aipc-assets.php` | Per-screen JS/CSS + `wp_add_inline_script` config for the console |
| `admin/views/*.php` | One template per screen (new-post, rewrite, review, connections, prompts, schedule, settings, logs, log-detail) |
| `assets/admin-agent.js` | The console viewer: polls the read-only `/state` endpoint while the background runner drives the job; retry/cancel, publish controls |
| `tests/e2e/` | `e2e.js` (runner), `install.php`, `drive.php` (assertions), `mock-api.php` (AI/Bale/RSS mocks), `lint.js`, `make-translations.py` |
| `languages/` | `wp-ai-post-creator.pot`, `-fa_IR.po/.mo` (540 msgids, incl. 3 plural entries) |
| Root/community MDs | `README.md`, `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`, `SUPPORT.md`, `CODE_OF_CONDUCT.md`, `AGENTS.md`, `LICENSE` (GPL-2.0), `.github/ISSUE_TEMPLATE/`, `.github/PULL_REQUEST_TEMPLATE.md`, `.github/workflows/ci.yml` |

## Conventions that matter

- **Prompts live in `AIPC_Steps::registry()`** as default templates; user edits are
  stored in the `aipc_steps` option. A stored prompt identical to the default is
  treated as "not customized" (auto-improves on updates). Add new steps there —
  never hardcode prompt text inside the agent.
- **Placeholders use `{{name}}`** and are documented in each registry entry's
  `placeholders` array (shown in the admin UI).
- **Step kinds:** `system` (system prompt), `chat_json` (JSON-only reply),
  `chat_html` (raw-HTML reply), `image` (image generation).
- **Escaping:** views use `esc_html__/esc_attr__/esc_url`; agent log messages are
  plain text (escaped at render). Never echo unescaped model output.
- **Retry semantics:** per connection in the chain, `aipc_step_attempts` (default 3)
  attempts; then fall back to the next connection; if the whole `image` chain fails,
  the step is skipped gracefully (post continues without a featured image).
- **Translations:** plain `__( '...', 'wp-ai-post-creator' )` calls; `_n()` for
  plurals (the tooling handles plural .po blocks and the `\x00`-joined .mo form).
  After adding strings run `make-translations.py`; if it aborts with MISSING, add
  Persian translations to its `NEW_TRANSLATIONS` dict and re-run.

## Patching strategy (proven to work)

When making multi-point PHP edits, use **one Python script per feature** that does
`assert old in src` before every replace, then rewrites the file — an assertion
failure aborts atomically instead of leaving half-patched files. Grep the real
context first (indentation is tabs; row blocks in views use 5–7 tab levels, which
breaks naive matching — match exact tab counts or use line-based surgery).

## Known pitfalls

- **PHP single quotes:** `'\n'` is a literal backslash-n. Use `"\n"` or `PHP_EOL`.
- **`esc_url_raw()` invents `http://`** for scheme-less strings — validate the
  scheme of the *input* before escaping (see the `source_sites` sanitizer).
- **Bale API URL:** `https://tapi.bale.ai/bot<TOKEN>/<method>` — no trailing slash
  after `/bot`, raw token. `sendMessage`/`sendPhoto` bodies: `chat_id`, `text` /
  `photo` + `caption` (≤4096 chars).
- **`pre_http_request` mocks:** return `$preempt` untouched when it is not null
  (earlier filters may have already intercepted), and put failure-injection
  branches **before** the host whitelist. Log the request *before* any branch that
  might return early, or your test counting will lie.
- **api-client has one internal retry on 429/5xx** — one agent-level attempt can
  produce **two** logged HTTP requests (3 agent attempts ⇒ 6 logged calls on an
  always-failing provider).
- **`aipc_post_created` fires once per job** from `execute_step()` when the
  finished state is saved — do not also fire it from `step_*finalize()` or Bale
  will notify twice.
- **Never run two edit_file calls on the SAME file in one parallel batch** —
  each is applied to the same base snapshot and the last writer silently drops
  the others' changes (this corrupted class-aipc-agent.php and class-aipc-rest.php
  during v1.6). Sequential edits or a Python patch script with `assert`ed
  anchors only (see "Patching strategy").
- **drive.php style:** named closures + `foreach`, `use ($log_file)` on closures
  that need outer variables; no nested `array_map(array_filter(closure))` one-liners.
- **install.php** sets `WP_INSTALLING` before requiring `wp-load.php` and clears
  `wp-content/database/*` + `mock-api-log.jsonl` for a fresh run.
- **Run e2e on Node 22+** — under Node 20 the php-wasm host-filesystem layer
  fails the Git-updater group (false `update_ok`/`backup_ok` leaves). CI pins
  Node 22; the sandbox default (v22) is fine.
- **The .mo is compiled by hand** (`make-translations.py`) — if you change the
  header or plural handling, verify with a real `load_textdomain()` round-trip
  in the e2e run (the `i18n_fa` group covers this).

## Release checklist (short version)

The change you are making probably matches a recipe in
[`docs/COOKBOOK.md`](docs/COOKBOOK.md) — follow it. Release steps are in
[`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md); the full list:
bump `AIPC_VERSION` (constant + plugin header), `readme.txt` stable tag +
changelog, `README.md` version + feature sections, re-run translations, lint +
e2e green, commit `vX.Y.Z — summary`, push the arena branch, update PR #1 via
REST PATCH, confirm mergeable.

## Roadmap

Full plan with rationale and the binding decision log:
[`docs/ROADMAP.md`](docs/ROADMAP.md). Summary:

- **1.6 — infrastructure (SHIPPED in v1.6.0):** CI (GitHub Actions running this
  e2e), custom DB table for jobs + schema versioning, self-rescheduling cron
  runner for true background execution (no Action Scheduler — decision D4),
  SSRF hardening + REST rate limiting, plus a draft review inbox.
- **1.7 — content/SEO:** topic clusters (pillar–cluster), topic queue + content
  calendar, automatic old-post refresh schedules, E-E-A-T signals.
- **1.8 — distribution:** wp.org submission prep, freemium/Pro licensing, SaaS-y
  UX (onboarding wizard, chart dashboard, style templates, settings import/export).
- Smaller candidates: in-post image gallery, audio/podcast (TTS) output, Markdown
  export + social cross-posting, custom HTML templates.
