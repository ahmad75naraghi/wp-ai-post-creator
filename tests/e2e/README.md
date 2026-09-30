# E2E Test Suite

Real-WordPress end-to-end tests for AI Post Creator. The suite boots **WordPress
6.7.1 on SQLite** (via [wp-sqlite-db](https://github.com/aaemnnosttv/wp-sqlite-db))
inside [php-wasm](https://github.com/WordPress/php-wasm), activates the plugin,
configures connections/schedules/Bale, and drives the agent **through the real
REST stack** against scripted mock providers.

Status at v1.7.4: **49 result groups / 335 boolean assertions green, zero PHP
warnings or deprecations.** (v1.6 added the `help_tooltips` group; v1.7 added
`topic_queue` and `bale_commands` — the latter stages scripted getUpdates
payloads through the Bale mock; v1.7.3 adds `assets_enqueue`, which proves
CSS/JS still enqueue when the admin menu title is translated, e.g. fa_IR.) The same suite runs in CI (`.github/workflows/ci.yml`).

## Files

| File | Role |
|---|---|
| `e2e.js` | Runner: boots php-wasm, runs `install.php` then `drive.php` |
| `install.php` | Phase 1: fresh WP install, activation, connections (Chat Mock, Image Mock), custom FAQ prompt, categories, settings (site prompt, `source_sites`), Bale config (2 recipients, due report), 3 schedule entries, daily limit 1 |
| `drive.php` | Phase 2: all assertions — prints `===E2E_JSON===` + a JSON object whose boolean leaves must all be true |
| `mock-api.php` | mu-plugin: intercepts `wp_remote_*` (AI providers, Bale, RSS) and logs every request to `wp-content/mock-api-log.jsonl` |
| `lint.js` | Syntax lint of all plugin PHP files (PHP 7.4 target) with php-parser |
| `.github/workflows/ci.yml` | CI: lint + `node --check` + translations freshness + this e2e suite on GitHub runners (workspace built per this README; `node_modules` + WordPress cached) |
| `make-translations.py` | Translation pipeline (extract → validate → pot/po/mo); see the file header |

## Workspace setup (once per environment)

The suite needs `node_modules` and a WordPress install **outside** the repo.
⚠️ In the Arena sandbox the conventional location `/home/user/.cache/e2e` is
**wiped between turns** — expect to rebuild it (this is normal, not a bug).

```bash
mkdir -p ~/wp-e2e && cd ~/wp-e2e
npm init -y && npm i php-parser@3 @php-wasm/universal @php-wasm/node
curl -sL -o wp.tar.gz  "https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/6.7.1"
tar -xzf wp.tar.gz && mv WordPress-6.7.1 wordpress
curl -sL -o sql.tar.gz "https://codeload.github.com/aaemnnosttv/wp-sqlite-db/tar.gz/refs/heads/master"
tar -xzf sql.tar.gz && cp wp-sqlite-db-master/src/db.php wordpress/wp-content/db.php
mkdir -p wordpress/wp-content/mu-plugins wordpress/wp-content/plugins
```

Notes:
- No PHP CLI is needed — php-wasm runs PHP 8.3 in Node; php-parser (lint) parses
  with a **7.4 target** so 7.4-only syntax is enforced.
- **Use Node 22+** — under Node 20 the php-wasm host-filesystem layer
  misbehaves on the Git-updater group's heavy file operations (PclZip
  extraction, recursive backup/swap), producing false leaves. CI pins Node 22.
- The repo copies of `lint.js`/`make-translations.py` resolve their plugin root
  from the script location (`$AIPC_ROOT` overrides) and `lint.js` looks for
  php-parser in `$E2E_HOME`, the cwd, or `~/.cache/e2e`.

## Running

```bash
REPO=/path/to/wp-ai-post-creator
cd ~/wp-e2e

# lint (can run from the repo itself)
node "$REPO/tests/e2e/lint.js"

# translations (can run from the repo itself)
python3 "$REPO/tests/e2e/make-translations.py"

# full e2e — ALWAYS recopy the plugin and the mock first:
rm -rf wordpress/wp-content/plugins/wp-ai-post-creator
cp -r "$REPO" wordpress/wp-content/plugins/ && rm -rf wordpress/wp-content/plugins/wp-ai-post-creator/.git
cp "$REPO"/tests/e2e/{e2e.js,install.php,drive.php} .
cp "$REPO/tests/e2e/mock-api.php" wordpress/wp-content/mu-plugins/
node e2e.js
```

`e2e.js` sets `E2E_WP_ROOT=$PWD/wordpress` for both phases. Phase 1 must print
`"installed":true`; phase 2 prints the assertion JSON. A quick pass/fail check:

```bash
python3 - <<'PY'
import json,sys
src=open('/tmp/e2e.log',encoding='utf-8').read()
data=json.loads(src[src.index('{\n    "unit_extract_json"'):])
def flat(d,p=''):
    for k,v in d.items():
        yield from (flat(v,p+'.'+k) if isinstance(v,dict) else [(p+'.'+k,v)])
bad=[k for k,v in flat(data) if v is False]
print('FALSE leaves:', bad or 'none'); sys.exit(1 if bad else 0)
PY
```

## Mock provider routing (`mock-api.php`)

`pre_http_request` (priority 10). Requests are logged **before** any early
return so counters never lie; `$preempt` is returned untouched when non-null.

| Host / trigger | Response |
|---|---|
| `tapi.bale.ai` | Bale Bot API: tokens containing `bad` → 401; `getUpdates` → chat 98765; otherwise `ok:true` |
| `news.invalid/*/feed/` | RSS 2.0 with 2 Persian gardening items (research sources) |
| `flaky.invalid` | Always 500 (fallback-chain test) |
| `raw.githubusercontent.com` | Git version check + connection test (host logged as `git-raw` with repo/branch/auth): repo or branch containing `notfound` → 404; an Authorization header containing `bad` → 401; branch `old` → `Version: 0.0.1`; everything else → `Version: 9.9.9` |
| `api.github.com` | Git zipball **with token** (host logged as `git-zip-api` with auth): same package rules as codeload (v1.5.2) |
| `codeload.github.com` | Git zipball **anonymous** (host logged as `git-zip`): branch `bad` → garbage; otherwise a PclZip archive built from the **live plugin folder** with the version string bumped to 9.9.9 plus an `updated-marker.txt` |
| `mock.invalid` / `images.invalid` | OpenAI-compatible provider, routed by prompt substring |
| `GET /models` | `mock-mini`, `mock-pro`, `dall-e-3` |
| `POST /images/generations` | 1×1 PNG as `b64_json` |
| prompt contains `"toc_title"` | plan JSON (topic taken from the user-suggested or invented marker) |
| `"sections"` | outline JSON (section count from `exactly N main sections`) |
| `INTRODUCTION` / `You are writing section` / `CONCLUSION` | Persian HTML content |
| `COPYWRITING & SEO REVISION PASS` | full improved article HTML (N sections + conclusion) |
| `REWRITE ANALYSIS` (v1.5) | rw_analyze JSON: improved title, notes, 2-section outline, internal link parsed from the candidates list |
| `FULL REWRITE` (v1.5) | rewritten article HTML: intro + N `<h2>` sections + conclusion, keeps `https://example.com/old`, injects the internal link |
| `FAQ questions` / `FAQ-QUESTIONS-CUSTOM` | FAQ JSON |
| `"meta_title"` | SEO JSON (meta, slug `balcony-vegetable-gardening-guide`, tags) |
| `image-generation prompt` | image prompt JSON |

The logged `prompt` field keeps **4000 chars** (needed for grounding assertions
deep inside the plan prompt).

## What the suite covers (v1.5)

- JSON extraction unit tests; connections/steps config (chain format, 13-step
  registry, custom prompt survives)
- REST permissions (anonymous → 401), connection test/models
- **Full new-post run** through REST: 12 calls, per-step connections (image on
  Image Mock), post/meta/terms/thumbnail assertions
- **fa_IR translation bundle** loads from the real `.mo` (incl. plural forms)
- **v1.5:** research sources (RSS fetched, plan prompt grounded with the feed
  items, recent-posts + link-candidates blocks present)
- **v1.5:** publish-now (post + result status) and publish-delay (event
  scheduled in window; handler called twice → idempotent; job log; Bale 🎉)
- **v1.5:** full rewrite run (same post id, new title, status kept, old link
  kept, internal link injected, H2 count, FAQ + schema, thumbnail, tags) and
  validation of bad rewrite targets / bad mode
- **v1.5:** fallback chain — plan step chained [Flaky → Chat Mock]: 3 failed
  attempts on Flaky (6 logged HTTP calls — the client retries 5xx once
  internally), switch + retry log lines, then success on Chat Mock
- **v1.5.1/1.5.2:** the Git self-updater end-to-end (runs **last** — it replaces the plugin files): repo/branch/token sanitizing, write-only token storage (empty keeps) in a non-autoloaded option, remote version (9.9.9 / 0.0.1 / missing branch → error), connection test (ok / repo 404 / rejected token), anonymous codeload download for the corrupt-package case, authenticated api.github.com download for the real swap, downgrade guard leaves the live files untouched, a real swap updates the version on disk (9.9.9 via `get_plugin_data`), removes a stale file, keeps the plugin active, writes a restorable backup and cleans the temp dirs.
- **v1.6:** the outbound network guard (public/loopback allowed; private IPv4
  + IPv6 ULA/link-local/mapped/metadata ranges blocked; allowlist wildcard and
  both toggle filters; call sites: connections sanitize, source_sites, REST
  connection test, image download); the jobs table (round-trip, light-row
  columns, counters, `running_ids`, retention pruning, legacy-option fallback
  via filter, and a full migration from a 1.5-style option); the background
  runner (event armed by `create_job`, whole run driven server-side with zero
  REST `/step` calls, event dropped on completion, idempotent re-run); the
  read-only `/state` endpoint (shape, read-only-ness, 404, anonymous 401,
  cancel drops the runner event); REST rate limiting (limit 2 → third call
  429 `aipc_rate`); the draft review inbox (renders the AI drafts with
  origin/publish/rewrite actions, publish flow fires `aipc_post_published`,
  published posts leave the inbox, double-publish refused); and the
  connection-delete fallback-chain cleanup (array + legacy key formats).
- **v1.5:** sanitizing of `source_sites` (scheme guard — `esc_url_raw` would
  invent `http://`) and of schedule publish fields
- Cancel / failure / manual-retry flows; scheduler tick (fires the due entry,
  daily limit blocks the second, catch-up state), report exactly once
- Admin pages render (logs list + detail, connections, prompts incl. the
  multi-select chain, rewrite picker, schedule incl. publish fields)
- Bale traffic accounting: per-chat photo/message delivery for every run type

## Conventions when extending

- New UI/behavior → add the assertion group **in the same PR**.
- Keep `drive.php` in the existing style: named closures + `foreach`, `use ($log_file)`
  where needed, no nested `array_map(array_filter(closure))` one-liners.
- Failure injection filters go in `drive.php` at priority **5** (before the
  mock), return `$preempt` untouched otherwise.
- `install.php` must stay idempotent: it wipes `wp-content/database/*` and the
  mock log, sets `WP_INSTALLING` before `wp-load.php`.
- Non-boolean values are fine in the JSON (counts, ids, arrays) — only boolean
  `false` fails the check.
