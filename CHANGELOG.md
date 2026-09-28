# Changelog

All notable changes to AI Post Creator are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) — versions follow the plugin header.

## [1.6.0] — 2026-09-29

### Added
- **Background job execution** — every job now runs server-side: a
  self-rescheduling cron event (`aipc_run_job`) drives the steps under the
  existing transient lock with a 600 s budget per run, then re-arms itself
  (30 s continue / 60 s on transient errors). The console became a pure
  **viewer**: it polls the new read-only `POST /aipc/v1/state` endpoint, so
  closing the browser tab never stops a run (with a stall warning after ~90 s
  without progress). The 15-min scheduler tick is a safety net that re-arms
  lost runner events. No Action Scheduler dependency (decision D4).
- **Jobs database table** — jobs moved from the `aipc_jobs` option (last 30,
  whole-blob rewrites) to a dedicated `{$wpdb->prefix}aipc_jobs` table
  (id, created/updated, status, mode, source, post_id, steps counters, call
  and token counters, topic, JSON payload; indexes on status/created/source)
  with schema versioning (`aipc_schema_version`) and an automatic migration
  from the option. A compatibility layer keeps `get_job()` /
  `get_all_jobs()` (now returning light rows) / `get_jobs_since()`, logs are
  served from the same queries, and retention is configurable
  (`aipc_job_retention_days`, default 90, 0 = keep forever) replacing the
  30-job/24 h caps. When the table cannot be created the store falls back to
  the legacy option transparently (`aipc_jobs_table_enabled` filter).
- **Outbound network guard (SSRF)** — new `AIPC_Network` class: all
  plugin-initiated outbound URLs (connection base URLs, research source
  sites, provider-returned image URLs, raw connection-test input) are checked
  — http(s) only, private/reserved/CGNAT/multicast and IPv6 ULA/link-local/
  mapped ranges blocked, loopback allowed by default for local LLMs (Ollama,
  LM Studio). Filters: `aipc_outbound_allowlist` (exact + `*.suffix`
  wildcards), `aipc_allow_private_hosts`, `aipc_allow_loopback`. Already
  stored URLs are grandfathered.
- **REST rate limiting** — per-user per-minute limits on `/start` (30),
  `/step` (240) and `/state` (300); HTTP 429 with code `aipc_rate`.
  Adjustable via `aipc_rest_rate_limit`.
- **Draft review inbox** — new "Review drafts" page (cap `edit_posts`):
  every AI-generated draft/pending post with status, word count, origin
  (manual/scheduled/rewrite) and modified time, plus quick actions — edit,
  preview, one-click publish (nonce + `publish_posts`, fires
  `aipc_post_published`) and rewrite-again deep link (preselects the post on
  the Rewrite page).
- **CI (GitHub Actions)** — `.github/workflows/ci.yml`: PHP syntax lint
  (PHP 7.4 target), `node --check` on the three JS bundles, translation
  completeness check (regenerate + `git diff`), and the full php-wasm e2e
  suite (WordPress 6.7.1 + SQLite) failing on any `false` boolean leaf or
  PHP warning; `node_modules` and the WordPress tarball are cached.

### Fixed
- Deleting a connection now also removes it from every per-step fallback
  chain (`connections[]`) and from legacy single-`connection` step configs —
  previously a deleted connection could stay referenced and stall a step.

### Changed
- The console's "working" copy and step driver were reworked for the
  background runner; `/step` still executes one step synchronously for
  compatibility.
- Scheduler internals now query the jobs table (`unfinished_cron`,
  `count_since`, `running_ids`) instead of loading every payload.
- Bale daily/weekly reports read job rows created since the last report
  (`get_jobs_since`) instead of scanning all stored jobs.

## [1.5.2] — 2026-09-27

### Added
- **Configurable Git connection** — repository (`owner/name`), branch and a
  write-only Personal Access Token, stored in a dedicated non-autoloaded
  option (`aipc_git`); all editable on the Update from Git page.
- **Connection test** — validates repo + branch + token together against
  GitHub (raw.githubusercontent.com) and reports the latest version; empty
  form token falls back to the stored one, so the saved setup can be tested
  as well as unsaved input.
- **Private-repository support** — with a token: authenticated version
  checks and downloads via the documented `api.github.com` zipball endpoint;
  without a token: the public codeload URL. HTTP 404/401/403 are mapped to
  human-readable messages (repo/branch not found, token rejected).

### Verified
- The e2e `git_updater` group grew to 32 assertions: repo/branch/token
  sanitizing, write-only token keep-on-empty, autoload-off storage,
  anonymous codeload download, authenticated api.github.com download,
  connection test (ok / 404 / rejected token), downgrade guard, corrupt
  package, real swap with backup/rollback, and the settings page fields.

## [1.5.1] — 2026-09-27

### Added
- **Git self-updater** — new "Update from Git" admin page (manage_options):
  downloads the repository zipball from codeload.github.com, verifies the
  plugin header, backs up the current files to `wp-content/aipc-backups/`,
  swaps the new files in atomically and rolls back automatically on failure.
  Remote version check against raw.githubusercontent.com (transient-cached),
  downgrade guard with an explicit "Reinstall anyway" option, selectable
  branch (`update_branch` setting, default `main`), stale-file cleanup,
  backup pruning (newest two kept) and full uninstall cleanup.

### Verified
- New e2e group `git_updater` (16 assertions): mocked GitHub raw + zipball
  hosts, downgrade guard leaves the live files untouched, corrupt package
  fails gracefully, a real swap updates the version on disk, removes a stale
  file, keeps the plugin active, creates a rollback-able backup and cleans
  the temp dirs.

## [1.5.0] — 2026-09-27

### Added
- **Rewrite mode** — pick any existing post and give it a full copywriting + SEO
  refresh: new `rw_analyze` + `rw_rewrite` steps, the post is updated in place
  (status, author, slug and category preserved, tags appended, optional new
  featured image), with structure validation (H2 count) and a minimum-length
  guard (≥ 50% of the original word count). New admin page "Rewrite post" with
  a post picker and live console.
- **Automatic internal linking** — the topic step plans up to 4 links to existing
  related posts and the writing/rewrite prompts weave them into the content.
- **Publish modes** — runs can now `publish immediately` or `publish after a
  15–10080-minute delay` (scheduled `aipc_publish_post` event, idempotent
  handler); drafts remain the default everywhere. Per-run in the console and per
  schedule entry; Bale sends a 🎉 notification when a delayed post goes live.
- **Connection fallback chains** — per-step ordered multi-select of connections;
  each connection gets its own retry budget (default 3 attempts) and the agent
  automatically switches to the next one on repeated failure. Image steps skip
  gracefully when the whole chain fails. Legacy single-connection settings
  migrate automatically.
- **Existing-post awareness** — the topic step receives the latest 30 published
  titles and is instructed not to duplicate them.
- **Research source sites** — up to 8 URLs (admin settings, strict http(s)
  sanitizing); their RSS feeds are fetched during planning/analysis and ground
  the topic and facts.
- Persian translations for all new strings (72 new + first plural `_n()` entries);
  committed dev tooling: `tests/e2e/lint.js` and `tests/e2e/make-translations.py`.
- Full documentation set: `AGENTS.md`, `CONTRIBUTING.md`, `SECURITY.md`,
  `CODE_OF_CONDUCT.md`, this changelog, and in `docs/`: `ARCHITECTURE.md`,
  `COOKBOOK.md` (development recipes), `ROADMAP.md` (plan + decision log),
  `REST-API.md`, `DEBUGGING.md`, `USER-GUIDE.fa.md`, `USER-GUIDE.en.md`,
  `RELEASE-CHECKLIST.md`.

### Changed
- Prompts & Steps admin page: multi-select connection chain replaces the single
  select; chain badges on each step card.
- REST `aipc/v1/start` validates and forwards `mode`, `post_id`, `publish_mode`,
  `publish_delay` (publish options require `publish_posts`).
- Bale notifications distinguish created / rewritten / published posts.
- Step registry grew to 13 editable steps.

### Verified
- E2E on real WordPress 6.7 (php-wasm + SQLite): **141/141 assertion groups green,
  zero PHP warnings** — including a full rewrite run, publish-now, publish-delay
  (event fired → published → notified), fallback-chain switching, RSS grounding,
  scheduler, daily limit and per-chat Bale delivery.

## [1.4.0] — 2026-09-26

### Added
- Multiple Bale recipients: any number of chat IDs (people or @channels, one per
  line); every post is delivered to all of them, with per-chat results logged.
- Periodic Bale report (daily/weekly): jobs, success/fail, drafts, tokens and
  per-connection usage at a chosen time/day.
- Daily limit for scheduled posts (0 = unlimited) with today's count on the
  Schedule page.
- REST `bale/test` now tests every recipient at once.

## [1.3.0] — 2026-09-25

### Added
- Automatic schedules (cron): any number of entries — local time, weekdays, fixed
  or auto-invented topic and full run options; 15-minute tick with same-day
  catch-up, no double-firing, resume of interrupted runs and auto-retry of failed
  ones (up to 3 ticks).
- "Run now" button starts a schedule entry immediately in the live console.
- Bale messenger notifications after every generated post (featured image +
  summary + link), with write-only bot token, automatic chat-ID detection and
  test messages.
- New admin page: Schedule & Notifications. Job logs show the run source
  (scheduled vs manual) and Bale delivery results.

## [1.2.0] — 2026-09-24

### Added
- Unlimited AI connections (base URL, API key, chat/image models, temperature,
  max tokens, timeout) with a default connection and write-only keys.
- Per-step connection routing (e.g. text with one provider, images with another).
- Editable per-step prompt templates with placeholder documentation and
  reset-to-default; untouched prompts auto-improve on updates.
- New admin panel: Connections, Prompts & Steps, and a complete Logs panel
  (summary stats, usage per connection, job history, per-call API details with
  model/tokens/duration/errors, console replay).
- Legacy provider settings migrate into a default connection automatically.

## [1.1.0] — 2026-09-23

### Added
- Site prompt + existing post categories drive topic invention (server-side
  validation; invalid category choices are retried).
- Copywriting & SEO revision pass over the whole draft.
- Rank Math (+Yoast) SEO summary: meta title/description, focus keyword, slug,
  excerpt, tags.
- Featured image generated from the topic + summary.
- Per-step automatic retries (3 attempts) and a manual "Retry step" button.
- Results always saved as drafts for review.

## [1.0.0] — 2026-09-22

### Added
- Initial release: agent-style post generation against any OpenAI-compatible
  API, live agent console with progress bar, settings with connection test and
  model loading, 12-step pipeline from topic to finished post.
