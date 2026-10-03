# Architecture

Technical reference for AI Post Creator **v1.17.0**. Audience: contributors and
AI agents working on the code. For usage, see the user guides
([فارسی](USER-GUIDE.fa.md) · [English](USER-GUIDE.en.md)).

- Runtime targets: **WordPress 5.7+ / PHP 7.4+**, no build step, no runtime
  dependencies (test tooling uses Node + Python, dev-only).
- Text domain `wp-ai-post-creator`, Persian (`fa_IR`) fully translated.

## 1. Bootstrap

`wp-ai-post-creator.php` defines `AIPC_VERSION` / `AIPC_PLUGIN_DIR` /
`AIPC_PLUGIN_URL`, requires all classes from `includes/`, then boots on
`plugins_loaded` (`aipc_boot()`):

| Registration | Purpose |
|---|---|
| `AIPC_Connections::maybe_migrate()` / `AIPC_Job_Store::maybe_upgrade()` | one-time migrations (legacy connection format; jobs option → `aipc_jobs` table + schema versioning) |
| `AIPC_Admin::register()` | menu (9 pages) + `admin_post_*` handlers |
| `AIPC_Assets::register()` | per-screen JS/CSS |
| `AIPC_Post_Builder::register()` | `the_content` FAQ-schema append + front CSS for generated posts |
| `AIPC_Scheduler::register()` + `maybe_schedule()` | `cron_schedules` filter (`aipc_quarter_hour`, 900 s), `aipc_cron_tick`, **`aipc_publish_post`**, **`aipc_run_job`** (background runner) |
| `AIPC_Bale::register()` | `aipc_post_created`, `aipc_post_published` notifications; `future_to_publish` announces Bale-scheduled posts |
| `AIPC_Bale_Commands::register()` + `maybe_schedule()` | `aipc_bale_5min` interval + `aipc_bale_poll` (two-way commands); also polls on every scheduler tick as a safety net |
| `add_action( 'rest_api_init', … 'AIPC_REST::register' )` | REST namespace `aipc/v1` |
| `aipc_daily_cleanup` → `AIPC_Agent::cleanup_static` | daily job GC (retention pruning) |

Activation seeds `aipc_settings`, creates/upgrades the jobs table and
schedules `aipc_cron_tick` (15 min) + `aipc_daily_cleanup` (daily); the
Plugins-screen row gets quick action links (New AI Post, Rewrite post,
Connections, Prompts & Steps, Logs, Schedule, Settings).

## 2. Class inventory

| Class (file) | ~LOC | Responsibility |
|---|---|---|
| `AIPC_Agent` (`class-aipc-agent.php`) | ~2100 | The heart: job facade over `AIPC_Job_Store`, step manifests, the chain-retry execution loop, every `step_*()` implementation, context helpers (recent posts, link candidates, RSS sources), stats |
| `AIPC_Bale` (`class-aipc-bale.php`) | ~780 | Bale/Telegram Bot API client (platform-switchable endpoint): per-post notify (sendPhoto/sendMessage), publish notify, inline publish/schedule keyboard on draft notifications, periodic reports, chat-ID detection, `getUpdates` with offset |
| `AIPC_Bale_Commands` (`class-aipc-bale-commands.php`) | ~470 | Two-way Bale: 5-min poll (safety net on the scheduler tick), command parsing (نوشتن/وضعیت/آخرین/انتشار/صف/راهنما), callback-button handling (publish now / schedule with Jalali+Gregorian date parsing; interactive menu: new-topic conversation, queue-run buttons, drafts list + action cards), authorized-chats-only, daily cap, `last_update_id` persistence |
| `AIPC_Topic_Queue` (`class-aipc-topic-queue.php`) | ~370 | FIFO topic bank (option-backed, pending/used with dedup memory), RSS suggestions (cleaned headlines, deduped vs queue + recent posts) |
| `AIPC_Scheduler` (`class-aipc-scheduler.php`) | ~620 | Cron tick, entries (incl. `use_queue`), daily limit, catch-up state, `aipc_publish_post` handler, **background runner** (`aipc_run_job`) |
| `AIPC_Job_Store` (`class-aipc-job-store.php`) | ~550 | Jobs storage: `{$wpdb->prefix}aipc_jobs` table (schema versioning, legacy-option migration + fallback), CRUD, light-row queries, retention pruning |
| `AIPC_Text` (`class-aipc-text.php`) | ~90 | Persian half-space (ZWNJ) repair rules + `&zwnj;` entity armoring for post content (v1.10.0) |
| `AIPC_Trace` (`class-aipc-trace.php`) | ~220 | Full API trace log (v1.11.0): JSONL request/response capture with job/step context, key redaction, base64 collapsing, 8 MB rotation, protected dir |
| `AIPC_Network` (`class-aipc-network.php`) | ~260 | Outbound network guard (SSRF): `is_safe_url()` / `validate_url()`, private-range blocking, allowlist + loopback filters |
| `AIPC_API_Client` (`class-aipc-api-client.php`) | ~500 | OpenAI-compatible HTTP: chat completions (JSON extraction + corrective retries), image generations, model listing; one internal retry on 429/5xx |
| `AIPC_Steps` (`class-aipc-steps.php`) | ~480 | 13-step registry (label, kind, default prompt, placeholders) + per-step config storage |
| `AIPC_Admin` (`class-aipc-admin.php`) | ~760 | Menu (9 pages), `admin_post_*` form handlers, view rendering |
| `AIPC_Rest` (`class-aipc-rest.php`) | ~600 | REST endpoints, permissions & rate limits |
| `AIPC_Post_Builder` (`class-aipc-post-builder.php`) | ~370 | Assembles the final post: `create()` (new) and `update()` (rewrite), TOC/FAQ HTML, SEO meta, tags, featured image upload |
| `AIPC_Connections` (`class-aipc-connections.php`) | ~420 | Connection CRUD + sanitizing, default connection, write-only keys, purpose (chat/image/both) + priority, `for_purpose()` priority-ordered pools |
| `AIPC_Updater` (`class-aipc-updater.php`) | ~600 | Git self-update: repo/branch/token config, version check, connection test, zipball download (codeload or authenticated api.github.com), verification, backup + atomic swap with rollback |
| `AIPC_Settings` (`class-aipc-settings.php`) | ~250 | Settings (site prompt, source sites, defaults) + option lists (tones, lengths, languages, image sizes) |
| `AIPC_Assets` (`class-aipc-assets.php`) | ~260 | Locale-proof screen detection (`screen_for_hook()` parses the page slug after `_page_` — the hook prefix is the *translated* menu title), enqueue, inline config for the console JS |

## 3. Data model (wp_options)

| Option | Structure |
|---|---|
| `aipc_settings` | `content_language` (fa default when locale is fa), `default_tone`, `default_length`, `site_prompt` (≤4000), `source_sites` (newline-separated, ≤8, strict http(s), trailing slashes stripped), `image_enabled`, `image_size`, `add_toc`, `add_faq`, `system_prompt_extra`, `allow_private_hosts` (SSRF-guard opt-in for LAN gateways, default 0), `delete_on_uninstall` |
| `aipc_connections` | array of `{id (c_*), name, base_url, api_key, chat_model, image_model, temperature (0–2, default 0.7), max_tokens (≤16000), request_timeout (≥15), is_default}` — keys never leave the server |
| `aipc_steps` | `{step_id: {connections: [conn_id,…] (ordered fallback chain), prompt: '' = default}}` — reads also accept legacy `connection` (string) |
| `aipc_schema_version` | jobs-table schema version (`AIPC_Job_Store::SCHEMA_VERSION`); bump + migration routine on upgrade |
| **table `{$wpdb->prefix}aipc_jobs`** | one row per job: `id VARCHAR(40)` PK, `created`/`updated` BIGINT, `status`, `mode`, `source`, `post_id`, `steps_total`, `steps_done`, `calls`, `prompt_tokens`, `completion_tokens` INT, `topic`, `payload` LONGTEXT (full job JSON — the source of truth: `steps[]`, `log[]`, `calls[]`, `timings`, `usage`, `args`, `data`, `notified`); KEY `status`/`created`/`source`. Retention pruning via `aipc_job_retention_days` (default 90, 0 = forever). The legacy `aipc_jobs` option (≤30 jobs / 24 h) is migrated automatically and remains as a fallback when the table is unavailable or `aipc_jobs_table_enabled` returns false |
| `aipc_stats` (autoload off) | aggregate: jobs, done, calls, tokens, drafts, `by_connection{name: {calls, ok, tokens}}` |
| `aipc_schedule` | `entries[]` (`{id (sch_*), time HH:MM, days[0–6 Sun=0], enabled, topic, publish (draft/now/delay), publish_delay (15–10080), opts{tone,length,language,image,faq,toc}}`), `state{entry_id: Y-m-d fired}`, `settings{daily_limit}` |
| `aipc_git` (autoload off) | Git self-update configuration: `repo` (`owner/name`, default `ahmad75naraghi/wp-ai-post-creator`), `branch` (default `main`), `token` (write-only PAT — an empty field keeps the stored token) |
| `aipc_bale` | `enabled`, `token` (write-only), `chat_ids[]`, `report` (''/daily/weekly), `report_time`, `report_day` (weekday for weekly, default 6), `last_report` (Y-m-d), `two_way` (accept commands, 1.7.0), `last_update_id` (getUpdates offset) |
| `aipc_bale_msgmap` (autoload off) | `{"<chat_id>:<message_id>": post_id}` — sent draft-notification ids captured by `AIPC_Bale::notify()`, capped at 100; lets a Reply containing a date schedule exactly that post (1.17.0) |
| `aipc_topic_queue` | `items[]` (`{id (tq_*), text (≤400), norm (dedup key), source (manual/rss), added, status (pending/used), job_id, used_at}`) — FIFO bank consumed by schedule entries with `use_queue`; used items are kept as dedup memory and pruned by `AIPC_Topic_Queue::prune()` |

Post meta written by the builder: `_aipc_generated`, `_aipc_job`,
`_aipc_faq_schema` (FAQPage JSON-LD), `_aipc_meta_title`,
`_aipc_meta_description` (mirrored to Yoast `_yoast_wpseo_*` and Rank Math
`rank_math_*` keys + `rank_math_focus_keyword`).

## 4. The step registry (13 steps)

`system` (system prompt) · `plan` · `outline` · `intro` · `section` ·
`conclusion` · `copywrite` · `faq` · `seo` · `image_prompt` · `image` ·
`rw_analyze` · `rw_rewrite`

Kinds: `system`, `chat_json` (a "reply ONLY with JSON" instruction is appended
and the client extracts/repairs JSON), `chat_html` ("raw HTML only"), `image`.
`AIPC_Steps::get($step)` returns `{connections[], prompt}`; `prompt_for()`
falls back to the registry default; a stored prompt identical to the default is
treated as non-custom.

## 5. Job execution

### 5.1 Manifests

`create_job()` sanitizes args (`tone/length/language/language_custom/image/faq/
toc/mode/post_id/publish_mode/publish_delay`, delay clamped 15–10080) and builds
the step list:

- **new:** `plan → outline → intro → section_0…n-1 → conclusion → copywrite →
  [faq] → seo → [image_prompt → image] → finalize`
- **rewrite:** `rw_analyze → rw_rewrite → [faq] → seo → [image_prompt → image]
  → rw_finalize` (the post keeps its own category; `post_id` validated +
  `edit_post` capability checked at creation)

Section counts: short 3 / medium 5 / long 8 sections (≈600/1200/2200 words).

### 5.2 The chain-retry loop (`execute_step`)

```
resolve_connections(step):  step chain (validated ids) → default fallback
                            → filter aipc_step_connections (whole chain)
                            → legacy aipc_step_connection (primary, dedupe)
for each connection in chain:
    up to max_attempts (filter aipc_step_attempts, default 3):
        run_step(job, step, conn)          # throws on failure
        → log "Attempt i/N on «conn» failed (…) — retrying…"
    if chain has next: log "«conn» failed after N attempts — switching to «next»"
if step == image and the WHOLE chain failed:
    advance(job, 'skipped')                 # post continues without an image
else if still failing: job.status = error (manual retry available)
```

Each agent attempt may internally double (the API client retries once on
429/5xx), so an always-failing provider logs **2× attempts** HTTP calls.
A transient lock (`aipc_lock_<job>`, 600 s) prevents concurrent execution.
When a job reaches `done`, `aipc_post_created` fires **once** (`notified` flag).

### 5.2.1 Background runner (since v1.6.0)

Every job is driven **server-side** by a self-rescheduling single cron event on
`aipc_run_job` (`AIPC_Scheduler::schedule_runner/unschedule_runner/run_job`):

- `create_job()` arms the runner (10 s); `retry_job()` re-arms it;
  `cancel_job()` / terminal states drop the event.
- `run_job($id)` calls `AIPC_Scheduler::run_steps($id)` — the same
  execute-next-step loop the scheduler tick uses, with a **600 s budget**;
  while the job is still `running` it re-arms itself after **30 s** (budget
  exhausted) or **60 s** (transient `WP_Error`, e.g. lock contention).
- The 15-min tick (`aipc_cron_tick`) is a **safety net**: it resumes
  interrupted cron jobs and re-arms lost runner events for any `running` job.
- The console no longer executes anything: it polls the read-only
  `/aipc/v1/state` endpoint (see §6). `/step` still exists for
  backward compatibility and drives one step synchronously.
- Note: production WP-Cron throttles spawn to ~once/60 s; a system cron
  (`define('DISABLE_WP_CRON', true)` + crontab) gives smoother runner pacing.

### 5.3 Context helpers (fed into prompts as `{{placeholders}}`)

- `recent_posts_context(30)` — published titles (anti-duplication in `plan`)
- `link_candidates($exclude, 20)` — published titles + permalinks (`plan`,
  `rw_analyze`); in rewrite mode the post being rewritten is excluded
- `source_context()` — `source_sites` setting → `fetch_feed(url + '/feed/')`,
  max 5 sites × 6 items (title, link, 180-char excerpt)
- `sanitize_internal_links()` (≤ 4, esc_url_raw) and `internal_links_block()`
  (the "weave these in" instruction for writing prompts)

### 5.4 Finalization & publish modes (`finalize` / `rw_finalize`)

`AIPC_Post_Builder::create()`/`update()` saves the post (draft by default;
rewrite preserves status/author/slug/categories and appends tags). Then:

- `publish_mode = now` → `wp_update_post(publish)` (requires `publish_posts`,
  enforced at REST/entry level)
- `publish_mode = delay` → `wp_schedule_single_event(time + delay*60,
  'aipc_publish_post', [post_id, job_id])`; the handler in
  `AIPC_Scheduler::publish_post()` is **idempotent** (only drafts are
  published) and fires `aipc_post_published` → Bale 🎉 notify + job log line

## 6. REST API (namespace `aipc/v1`)

| Route | Method | Capability | Notes |
|---|---|---|---|
| `/start` | POST | `edit_posts` + rate limit | args: `topic, tone, length, language, language_custom, image, faq, toc, mode, post_id`; `publish_mode`/`publish_delay` only forwarded with `publish_posts`; returns `client_state()`; arms the background runner |
| `/step` | POST | `edit_posts` + rate limit | executes the next step synchronously (compat); `job_id`, `since` (log cursor) |
| `/state` | POST | `edit_posts` + rate limit | **read-only**: returns `client_state()` without executing anything (`job_id`, `since`) |
| `/cancel` | POST | `edit_posts` | cancels a running job |
| `/retry` | POST | `edit_posts` | resets the failed step for a manual retry |
| `/connection/test` | POST | `manage_options` | by saved `id` or raw `base_url`/`api_key`/`chat_model`; probes the `/v1` variant on failure and returns `fixed_base_url` when it works |
| `/connection/models` | POST | `manage_options` | lists chat + image models |
| `/bale/test` | POST | `manage_options` | `token`/`chat_ids` (or stored config) → tests every recipient |
| `/bale/chat-id` | POST | `manage_options` | `getUpdates` → latest chat id |
| `/topics/suggest` | POST | `manage_options` | RSS headline suggestions from `source_sites` (`limit`, default 12; cleaned + deduped vs queue and recent posts) |
| `/topics/add` | POST | `manage_options` | adds `texts[]` to the topic queue (`source` manual/rss); returns added/skipped/pending counts |

`client_state()` returns the job's public projection: status, progress, steps,
logs since cursor, usage, result (`post_id/title/edit/view/words/status`).
`/start`, `/step` and `/state` enforce a per-user per-minute rate limit
(30/240/300, filter `aipc_rest_rate_limit`; HTTP 429 `aipc_rate` when exceeded).
All outbound URLs (connection `base_url`, `source_sites`, provider-returned
image URLs, raw connection-test input) pass the `AIPC_Network` SSRF guard.

## 7. Admin surface

| Page (slug) | Capability | View | JS |
|---|---|---|---|
| New AI Post (`aipc`) | `edit_posts` | `admin/views/new-post.php` | `admin-agent.js` |
| Rewrite post (`aipc-rewrite`) | `edit_posts` | `admin/views/rewrite.php` | `admin-agent.js` (+ `mode: rewrite` via `extraArgs`; `?post=ID` preselects) |
| Review drafts (`aipc-review`) | `edit_posts` | `admin/views/review.php` | — (server-rendered) |
| Connections (`aipc-connections`) | `manage_options` | `connections.php` | `admin-connections.js` |
| Prompts & Steps (`aipc-prompts`) | `manage_options` | `prompts.php` | — |
| Logs (`aipc-logs`) | `manage_options` | `logs.php` / `log-detail.php` | — |
| Schedule (`aipc-schedule`) | `manage_options` | `schedule.php` | `admin-schedule.js` |
| Settings (`aipc-settings`) | `manage_options` | `settings.php` | — |
| Update from Git (`aipc-update`) | `manage_options` | `update.php` | — (inline confirm) |

`admin_post_*` handlers: `aipc_save_connection`, `aipc_delete_connection`,
`aipc_save_steps`, `aipc_clear_logs`, `aipc_delete_job`, `aipc_save_schedule`,
`aipc_delete_schedule`, `aipc_run_now` (redirects to the console with
`resumeJobId`), `aipc_save_bale`, `aipc_save_schedule_settings`,
`aipc_publish_draft` (review inbox → publish, `publish_posts`),
`aipc_add_topics` / `aipc_remove_topic` / `aipc_clear_topics` (topic queue),
`aipc_git_check` / `aipc_git_test` / `aipc_git_update` /
`aipc_save_update_settings` (Git self-updater) — all nonce-checked and
capability-checked.

The console (`assets/admin-agent.js`) is driven by an inline `CFG` object
(REST URL, nonce, i18n strings, defaults, `resumeJobId`, `extraArgs`). Since
v1.6.0 it is a **viewer**: `start` → poll the read-only `/state` endpoint with
a `since` cursor → render steps/logs/progress → `retry`/`cancel` buttons
(the background runner does the actual work, so closing the tab is safe —
a stall warning appears after ~90 s without progress).
`collectArgs()` merges `extraArgs` (rewrite `mode`/`post_id`) and the publish
controls.

## 8. Scheduler design

- Cron `aipc_cron_tick` every 900 s; **tick budget 600 s**, guard 80 iterations.
- Tick order: (1) due Bale report → (2) resume interrupted `running` jobs →
  (3) retry failed jobs (≤ 3 ticks, 1-day window) → (4) daily-limit check →
  (5) fire the earliest due entry (`entry_due()`: time window, weekday,
  `state[id] != today`; the fired date is recorded → no double-firing,
  same-day catch-up included).
- Firing an entry = `create_job(topic, opts + publish_mode/publish_delay,
  'cron')` driven synchronously within the tick (the runner event keeps it
  going if the tick budget runs out).
- Entries with `use_queue` take the oldest **pending** topic from
  `AIPC_Topic_Queue::peek()` (marked used with the job id afterwards) and
  fall back to the entry's fixed topic — or the site prompt — when the
  queue is empty.
- Daily limit counts cron-source jobs created today (0 = unlimited)
  (`AIPC_Job_Store::count_since`).
- The tick also re-arms lost `aipc_run_job` events for every `running` job
  (runner safety net, see §5.2.1) and runs the Bale command poll
  (`AIPC_Bale_Commands::poll`, priority 20) as a safety net for lost
  `aipc_bale_poll` events.

## 9. Bale integration

Base URL `https://tapi.bale.ai/bot<TOKEN>/<method>` (no trailing slash after
`/bot`, raw token). `sendMessage` (`text` ≤ 4096) and `sendPhoto`
(`photo` = URL, `caption` ≤ 4096) are the only used methods; `getUpdates`
powers chat-ID detection. Delivery is best-effort with per-chat results in the
job log; `aipc_post_created` → notify (image + summary + link, with
rewritten/published variants), `aipc_post_published` → 🎉 publish notify,
periodic report per `aipc_bale.report`.

Since v1.7.0 the bot is also **two-way** (`AIPC_Bale_Commands`): when
`two_way` is enabled, a 5-minute cron (`aipc_bale_poll`, safety-netted by the
scheduler tick) reads `getUpdates` past `last_update_id` and answers the
**configured chats only** — `نوشتن: <topic>` starts a background draft run
(source `bale`, capped at 20/day), plus `وضعیت` / `آخرین` / `انتشار [n]` /
`صف` / `راهنما`. Unknown chats are ignored silently; every reply starts with
a stable emoji (✍️📊📄🚀📋🤖) so tests can match it across translations.

**One-step scheduling (1.17.0).** The «زمان‌بندی» prompt ships four one-tap
presets (`aipc:when:<post>:<tonight|tom_am|tom_pm|d2_am>` — see
`preset_ts()`, site timezone, «tonight» rolls to tomorrow when past). A bare
parseable date with no pending question schedules the newest AI draft; a
date sent as a **Reply** to a draft notification schedules that exact post
via the `aipc_bale_msgmap` option (§3). `schedule_post()` refuses posts that
are already published.

**Jobs & Cron page (1.17.0).** `aipc-cron` submenu
(`AIPC_Admin::cron_overview()`): unfinished jobs (running/queued/error, per-job
Cancel → `AIPC_Agent::cancel_job()`, plus Cancel-all), pending
`aipc_publish_post` events (Cancel → `AIPC_Admin::unschedule_publish()`),
scheduled future AI posts (Back to draft), and the plugin's recurring cron
events read-only. admin-post actions: `aipc_cancel_job`,
`aipc_cancel_all_jobs`, `aipc_unschedule_publish`, `aipc_revert_future`.

## 10. Internationalization

797 msgids (`languages/wp-ai-post-creator-fa_IR.po`), fully translated,
including 3 `_n()` plural entries. Tooling (in-repo):
`tests/e2e/make-translations.py` extracts → validates → rebuilds pot/po and
hand-compiles the binary `.mo` (little-endian uint32 tables; plural originals
stored as `singular\x00plural`). New strings abort the run with a MISSING list
until translations are added to its `NEW_TRANSLATIONS` dict.

## 11. Testing

See [`tests/e2e/README.md`](../tests/e2e/README.md) for the full recipe:
real WordPress 6.7.1 + SQLite (wp-sqlite-db) running under php-wasm, driven
through the genuine REST stack against a mock OpenAI-compatible provider, a
mock Bale Bot API, mock RSS feeds and an always-failing provider. 66 result
groups / 507 assertions green at v1.17.0, zero PHP warnings. The same suite
runs on GitHub Actions (`.github/workflows/ci.yml`).

## 12. Hooks reference

**Actions:** `aipc_post_created($post_id, $job_id)` ·
`aipc_post_published($post_id, $job_id)` · `aipc_cron_tick` ·
`aipc_publish_post($post_id, $job_id)` · `aipc_daily_cleanup` ·
`aipc_run_job($job_id)` · `aipc_bale_poll` (5-min two-way command poll)

**Filters:** `aipc_git_version_ttl($ttl, $repo, $branch)` · `aipc_git_request_args($args, $url)` · `aipc_step_connections($chain, $step)` ·
`aipc_step_connection($primary_conn, $step)` (legacy) ·
`aipc_step_attempts($attempts, $job, $step_id)` (default 3) ·
`aipc_step_prompt($prompt, $step, $job)` · `aipc_messages($messages, $job, $step)` ·
`aipc_system_prompt($system, $job)` · `aipc_post_args($post_args, $job)` ·
`aipc_outbound_allowlist($hosts)` · `aipc_allow_private_hosts($bool)` ·
`aipc_allow_loopback($bool)` · `aipc_rest_rate_limit($limit, $route)` ·
`aipc_job_retention_days($days)` · `aipc_jobs_table_enabled($bool)`
