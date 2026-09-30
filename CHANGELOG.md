# Changelog

All notable changes to AI Post Creator are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) — versions follow the plugin header.

## [1.7.5] — 2026-09-30

### Improved
- **"Load models from the provider" now shows a visible model picker.**
  The models used to be poured only into an invisible `<datalist>` — with
  a success message saying "pick one in the list", users saw no list at
  all. A searchable, scrollable picker now opens under the Chat model
  field: type to filter, click to select (the datalist autocomplete is
  kept as a bonus). New strings translated to Persian (639 msgids).

### Tests
- `admin_pages.connections` gained a `model_picker` markup assertion
  (49 groups / 336 assertions total).

## [1.7.4] — 2026-09-30

### Fixed
- **Pasting a full endpoint URL as the base URL no longer breaks the
  connection.** Entering `…/v1/chat/completions` made every request hit
  `…/v1/chat/completions/models` (→ "Unknown API route" 404s).
  `AIPC_API_Client::base_url()` now strips well-known OpenAI endpoint paths
  (`/chat/completions`, `/completions`, `/responses`, `/models`,
  `/embeddings`, `/images/generations`) off the end, and a successful
  connection test returns `fixed_base_url` whenever normalization changed
  the typed URL — the Connections form auto-corrects the field.

### Tests
- New `base_url_normalize` e2e group (8 assertions): endpoint-path
  stripping incl. repeated suffixes, bare-host `/v1` append, legit custom
  paths kept, `fixed_base_url` reported on fix and absent on clean input
  (49 groups / 335 assertions total).

## [1.7.3] — 2026-09-30

### Fixed
- **Localized admins (fa_IR & co.) lost all plugin CSS/JS on every subpage.**
  WordPress builds submenu hooks from `sanitize_title()` of the *translated*
  top-level menu title, so the hardcoded English hook names in
  `AIPC_Assets::enqueue()` never matched on a Persian site — Connections,
  Settings, Rewrite, Review, Prompts, Logs and Schedule rendered without
  styles and their scripts (including the "Test connection" button) never
  loaded. Screen detection now parses the stable page slug after `_page_`
  (`AIPC_Assets::screen_for_hook()`), which is locale-proof.
- The **Update page** (`aipc-update`) was missing from the enqueue map
  entirely and never received the admin stylesheet, in any language.

### Tests
- New `assets_enqueue` e2e group (7 assertions): Persian-localized hook
  enqueues `aipc-admin` CSS + `aipc-connections` JS, English and toplevel
  hooks still work, the update page gets CSS, and foreign hooks enqueue
  nothing (48 groups / 327 assertions total).

## [1.7.2] — 2026-09-30

### Fixed
- **Connecting to AI gateways/routers (e.g. OmniRoute) no longer fails
  confusingly.** A base URL without a path now gets `/v1` appended
  automatically for every host (previously only `api.openai.com`); the
  connection test probes the `/v1` variant when the first attempt fails and
  auto-corrects the field (`fixed_base_url` in the REST response); and when
  an address returns a web page instead of an API response, the error says
  exactly that ("looks like a website URL, not an API base URL") instead of
  dumping HTML fragments.

### Added
- **"Allow private/LAN addresses" setting** (Settings → Advanced): lets a
  self-hosted gateway (OmniRoute, Ollama, LM Studio on another machine)
  be reached without writing a filter. Default off; loopback stays always
  allowed; the `aipc_allow_private_hosts` filter still has the final word.
- OmniRoute (`http://localhost:20128/v1`) added to the example endpoints,
  with a note about self-hosted gateways and LAN addresses.

### Changed — admin UI polish
- The Schedule page got the same header (logo + title + subtitle) as every
  other page instead of a bare `<h1>`.
- Field alignment unified: the grid/toggle gutters now apply only inside the
  boxed "Options" panels, so forms rendered directly in cards line up with
  the card edge (Connections, Prompts, Schedule, Update).
- Tables restyled consistently (soft header row, hover, rounded corners);
  key-value tables get their own class instead of inline widths.
- Day-of-week checkboxes on the Schedule form became selectable pills;
  topic-queue and contextual-help ("?") elements now follow the plugin's
  design tokens instead of WP-gray one-offs.
- All inline `style="…"` attributes removed from the views; every input,
  select and textarea got a consistent focus ring; responsive tweaks for
  narrow screens.
- fa_IR translation updated (637 msgids).

## [1.7.1] — 2026-09-30

### Documentation
- **Architecture reference** brought up to the current code: real bootstrap
  flow (`aipc_boot()` registrations, Plugins-screen quick links instead of the
  non-existent admin-bar shortcuts), 9 admin pages, refreshed class sizes,
  `aipc_topic_queue` option + new `aipc_bale` fields (`two_way`, `report_day`,
  `last_update_id`) in the data model, `/topics/*` REST routes, the complete
  `admin_post_*` handler list (topics + Git updater), the topic-queue
  consumption and Bale-poll safety net in the scheduler design, a two-way
  commands section and the `aipc_bale_poll` action in the hooks reference.
- **REST API reference**: documented `POST /aipc/v1/topics/suggest` and
  `POST /aipc/v1/topics/add` (params, responses, capability).
- **Roadmap**: status moved to v1.7.0, topic queue and two-way Bale commands
  marked shipped, 1.7.0 history row added.
- **README (Persian)**: the topic queue and Bale-commands bullets moved from
  the 1.6.0 section into their own 1.7.0 section.
- **User guides (fa/en)**: version headers updated; the stale FAQ answer
  claiming background execution was still "on the 1.6 roadmap" corrected.
- **AGENTS.md**: project summary updated to v1.7.x, current branch/PR state
  corrected (PR #1 and #2 merged), e2e contract numbers refreshed
  (46 groups / 441 assertions).

No functional changes.

## [1.7.0] — 2026-09-29

### Added
- **Topic queue** — a FIFO bank of topics on the Schedule page. Schedule
  entries can now "take the topic from the queue": each run consumes the
  oldest pending topic, then falls back to the entry's fixed topic (or the
  site prompt) when the queue is empty. Topics are added by hand (bulk,
  one per line) or pulled from the configured research sources with the
  **Suggest topics** button (REST `POST /aipc/v1/topics/suggest`, cleaned
  headlines, deduped against the queue and recent posts; `POST
  /aipc/v1/topics/add`). Used topics are remembered so they are never
  suggested twice. The queue is also visible from Bale (see below).
- **Two-way Bale commands** — the bot now *obeys*, not just notifies. With
  "Accept commands from Bale chats" enabled, a 5-minute WP-Cron event
  (plus every scheduler tick as a safety net) polls `getUpdates` and
  answers the **configured chats only**: `نوشتن: <topic>` starts a
  background draft run (capped at 20 per day), `وضعیت` reports today's
  runs/drafts/queue, `آخرین` shows the newest draft, `انتشار [n]`
  publishes the newest (or n-th) draft — Persian digits accepted — and
  `صف` lists pending topics. Processed updates are tracked by id
  (`last_update_id`) so commands never run twice; unknown commands get a
  hint, strangers are ignored silently. Fully translated (fa_IR).

### Changed
- Version bump to 1.7.0; e2e suite grew to 46 result groups / 441
  assertions (new `topic_queue` and `bale_commands` groups).

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
- **Contextual help ("?") on every section** — a small ? icon beside each
  section and key field across all admin pages expands into a short
  explanation of what that section does (pure `<details>`, no JS, RTL-safe,
  fully translated). 26 toggles across 10 screens.
- **Draft review inbox** — new "Review drafts" page (cap `edit_posts`):
  every AI-generated draft/pending post with status, word count, origin
  (manual/scheduled/rewrite) and modified time, plus quick actions — edit,
  preview, one-click publish (nonce + `publish_posts`, fires
  `aipc_post_published`) and rewrite-again deep link (preselects the post on
  the Rewrite page).
- **Community files** — full GPL-2.0 `LICENSE` text, refreshed
  `CONTRIBUTING.md`, new `SUPPORT.md` (bilingual help map), GitHub issue
  templates (bug report / feature request, bilingual) and a PR template
  carrying the verification checklist; README's docs/security/quality sections
  brought up to 1.6.
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
