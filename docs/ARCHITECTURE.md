# Architecture

Technical reference for AI Post Creator **v1.5.2**. Audience: contributors and
AI agents working on the code. For usage, see the user guides
([فارسی](USER-GUIDE.fa.md) · [English](USER-GUIDE.en.md)).

- Runtime targets: **WordPress 5.7+ / PHP 7.4+**, no build step, no runtime
  dependencies (test tooling uses Node + Python, dev-only).
- Text domain `wp-ai-post-creator`, Persian (`fa_IR`) fully translated.

## 1. Bootstrap

`wp-ai-post-creator.php` defines `AIPC_VERSION` / `AIPC_PLUGIN_DIR` /
`AIPC_PLUGIN_URL`, requires all classes from `includes/`, then registers:

| Registration | Purpose |
|---|---|
| `AIPC_Agent::register_static()` | cron `aipc_daily_cleanup` (job GC) |
| `AIPC_Scheduler::register()` | `cron_schedules` filter (`aipc_quarter_hour`, 900 s), `aipc_cron_tick`, **`aipc_publish_post`** |
| `AIPC_Bale::register()` | `aipc_post_created`, `aipc_post_published` |
| `AIPC_Rest::register()` | REST namespace `aipc/v1` |
| `AIPC_Admin::register()` | menu + `admin_post_*` handlers + settings |
| `AIPC_Assets::register()` | per-screen JS/CSS |

Activation schedules `aipc_cron_tick` (15 min) and `aipc_daily_cleanup` (daily);
the admin bar gets "New AI Post" + "Rewrite post" shortcuts for users with
`edit_posts`.

## 2. Class inventory

| Class (file) | ~LOC | Responsibility |
|---|---|---|
| `AIPC_Agent` (`class-aipc-agent.php`) | 2119 | The heart: job CRUD, step manifests, the chain-retry execution loop, every `step_*()` implementation, context helpers (recent posts, link candidates, RSS sources), stats |
| `AIPC_Bale` (`class-aipc-bale.php`) | 609 | Bale Bot API client: per-post notify (sendPhoto/sendMessage), publish notify, periodic reports, chat-ID detection |
| `AIPC_Scheduler` (`class-aipc-scheduler.php`) | 538 | Cron tick, entries, daily limit, catch-up state, `aipc_publish_post` handler |
| `AIPC_API_Client` (`class-aipc-api-client.php`) | 491 | OpenAI-compatible HTTP: chat completions (JSON extraction + corrective retries), image generations, model listing; one internal retry on 429/5xx |
| `AIPC_Steps` (`class-aipc-steps.php`) | 478 | 13-step registry (label, kind, default prompt, placeholders) + per-step config storage |
| `AIPC_Admin` (`class-aipc-admin.php`) | 464 | Menu (7 pages), `admin_post_*` form handlers, view rendering |
| `AIPC_Rest` (`class-aipc-rest.php`) | 411 | REST endpoints & permissions |
| `AIPC_Post_Builder` (`class-aipc-post-builder.php`) | 367 | Assembles the final post: `create()` (new) and `update()` (rewrite), TOC/FAQ HTML, SEO meta, tags, featured image upload |
| `AIPC_Connections` (`class-aipc-connections.php`) | 303 | Connection CRUD + sanitizing, default connection, write-only keys |
| `AIPC_Updater` (`class-aipc-updater.php`) | ~430 | Git self-update: repo/branch/token config, version check, connection test, zipball download (codeload or authenticated api.github.com), verification, backup + atomic swap with rollback |
| `AIPC_Settings` (`class-aipc-settings.php`) | 245+ | Settings (site prompt, source sites, defaults) + option lists (tones, lengths, languages, image sizes) |
| `AIPC_Assets` (`class-aipc-assets.php`) | 209 | Screen detection, enqueue, inline config for the console JS |

## 3. Data model (wp_options)

| Option | Structure |
|---|---|
| `aipc_settings` | `content_language` (fa default when locale is fa), `default_tone`, `default_length`, `site_prompt` (≤4000), `source_sites` (newline-separated, ≤8, strict http(s), trailing slashes stripped), `image_enabled`, `image_size`, `add_toc`, `add_faq`, `system_prompt_extra`, `delete_on_uninstall` |
| `aipc_connections` | array of `{id (c_*), name, base_url, api_key, chat_model, image_model, temperature (0–2, default 0.7), max_tokens (≤16000), request_timeout (≥15), is_default}` — keys never leave the server |
| `aipc_steps` | `{step_id: {connections: [conn_id,…] (ordered fallback chain), prompt: '' = default}}` — reads also accept legacy `connection` (string) |
| `aipc_jobs` (autoload off) | last 30 jobs, each: `id (job_*)`, `status` (`running`/`done`/`error`/`cancelled`), `mode` (`new`/`rewrite`), `topic`, `source` (`manual`/`cron`), `post_id`, `steps[]` (`{id,label,status}`), `cursor`, `log[]` (`{t,msg,level}`), `calls[]` (`{step,conn,model,ok,ms,error,tokens}`), `timings`, `usage{prompt,completion,calls}`, `args` (sanitized run options), `data` (plan/outline/content/rewrite/result), `notified` |
| `aipc_stats` (autoload off) | aggregate: jobs, done, calls, tokens, drafts, `by_connection{name: {calls, ok, tokens}}` |
| `aipc_schedule` | `entries[]` (`{id (sch_*), time HH:MM, days[0–6 Sun=0], enabled, topic, publish (draft/now/delay), publish_delay (15–10080), opts{tone,length,language,image,faq,toc}}`), `state{entry_id: Y-m-d fired}`, `settings{daily_limit}` |
| `aipc_git` (autoload off) | Git self-update configuration: `repo` (`owner/name`, default `ahmad75naraghi/wp-ai-post-creator`), `branch` (default `main`), `token` (write-only PAT — an empty field keeps the stored token) |
| `aipc_bale` | `enabled`, `token` (write-only), `chat_ids[]`, `report` (''/daily/weekly), `report_time`, `last_report` (Y-m-d) |

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
Steps run one per REST `step` call (no long-running PHP); a transient lock
(`aipc_lock_<job>`, 600 s) prevents concurrent execution. When a job reaches
`done`, `aipc_post_created` fires **once** (`notified` flag).

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
| `/start` | POST | `edit_posts` | args: `topic, tone, length, language, language_custom, image, faq, toc, mode, post_id`; `publish_mode`/`publish_delay` only forwarded with `publish_posts`; returns `client_state()` |
| `/step` | POST | `edit_posts` | executes the next step; `job_id`, `since` (log cursor) |
| `/cancel` | POST | `edit_posts` | cancels a running job |
| `/retry` | POST | `edit_posts` | resets the failed step for a manual retry |
| `/connection/test` | POST | `manage_options` | by saved `id` or raw `base_url`/`api_key`/`chat_model` |
| `/connection/models` | POST | `manage_options` | lists chat + image models |
| `/bale/test` | POST | `manage_options` | `token`/`chat_ids` (or stored config) → tests every recipient |
| `/bale/chat-id` | POST | `manage_options` | `getUpdates` → latest chat id |

`client_state()` returns the job's public projection: status, progress, steps,
logs since cursor, usage, result (`post_id/title/edit/view/words/status`).

## 7. Admin surface

| Page (slug) | Capability | View | JS |
|---|---|---|---|
| New AI Post (`aipc`) | `edit_posts` | `admin/views/new-post.php` | `admin-agent.js` |
| Rewrite post (`aipc-rewrite`) | `edit_posts` | `admin/views/rewrite.php` | `admin-agent.js` (+ `mode: rewrite` via `extraArgs`) |
| Connections (`aipc-connections`) | `manage_options` | `connections.php` | `admin-connections.js` |
| Prompts & Steps (`aipc-prompts`) | `manage_options` | `prompts.php` | — |
| Logs (`aipc-logs`) | `manage_options` | `logs.php` / `log-detail.php` | — |
| Schedule (`aipc-schedule`) | `manage_options` | `schedule.php` | `admin-schedule.js` |
| Settings (`aipc-settings`) | `manage_options` | `settings.php` | — |
| Update from Git (`aipc-update`) | `manage_options` | `update.php` | — (inline confirm) |

`admin_post_*` handlers: `aipc_save_connection`, `aipc_delete_connection`,
`aipc_save_steps`, `aipc_clear_logs`, `aipc_delete_job`, `aipc_save_schedule`,
`aipc_delete_schedule`, `aipc_run_now` (redirects to the console with
`resumeJobId`), `aipc_save_bale`, `aipc_save_schedule_settings` — all
nonce-checked and capability-checked.

The console (`assets/admin-agent.js`) is driven by an inline `CFG` object
(REST URL, nonce, i18n strings, defaults, `resumeJobId`, `extraArgs`). The
loop: `start` → poll `step` with a `since` cursor → render steps/logs/progress
→ `retry`/`cancel` buttons; `collectArgs()` merges `extraArgs` (rewrite
`mode`/`post_id`) and the publish controls.

## 8. Scheduler design

- Cron `aipc_cron_tick` every 900 s; **tick budget 600 s**, guard 80 iterations.
- Tick order: (1) due Bale report → (2) resume interrupted `running` jobs →
  (3) retry failed jobs (≤ 3 ticks, 1-day window) → (4) daily-limit check →
  (5) fire the earliest due entry (`entry_due()`: time window, weekday,
  `state[id] != today`; the fired date is recorded → no double-firing,
  same-day catch-up included).
- Firing an entry = `create_job(topic, opts + publish_mode/publish_delay,
  'cron')` driven synchronously within the tick.
- Daily limit counts cron-source jobs created today (0 = unlimited).

## 9. Bale integration

Base URL `https://tapi.bale.ai/bot<TOKEN>/<method>` (no trailing slash after
`/bot`, raw token). `sendMessage` (`text` ≤ 4096) and `sendPhoto`
(`photo` = URL, `caption` ≤ 4096) are the only used methods; `getUpdates`
powers chat-ID detection. Delivery is best-effort with per-chat results in the
job log; `aipc_post_created` → notify (image + summary + link, with
rewritten/published variants), `aipc_post_published` → 🎉 publish notify,
periodic report per `aipc_bale.report`.

## 10. Internationalization

468 msgids (`languages/wp-ai-post-creator-fa_IR.po`), fully translated,
including 2 `_n()` plural entries. Tooling (in-repo):
`tests/e2e/make-translations.py` extracts → validates → rebuilds pot/po and
hand-compiles the binary `.mo` (little-endian uint32 tables; plural originals
stored as `singular\x00plural`). New strings abort the run with a MISSING list
until translations are added to its `NEW_TRANSLATIONS` dict.

## 11. Testing

See [`tests/e2e/README.md`](../tests/e2e/README.md) for the full recipe:
real WordPress 6.7.1 + SQLite (wp-sqlite-db) running under php-wasm, driven
through the genuine REST stack against a mock OpenAI-compatible provider, a
mock Bale Bot API, mock RSS feeds and an always-failing provider. 141/141
assertion groups green at v1.5.0, zero PHP warnings.

## 12. Hooks reference

**Actions:** `aipc_post_created($post_id, $job_id)` ·
`aipc_post_published($post_id, $job_id)` · `aipc_cron_tick` ·
`aipc_publish_post($post_id, $job_id)` · `aipc_daily_cleanup`

**Filters:** `aipc_git_version_ttl($ttl, $repo, $branch)` · `aipc_git_request_args($args, $url)` · `aipc_step_connections($chain, $step)` ·
`aipc_step_connection($primary_conn, $step)` (legacy) ·
`aipc_step_attempts($attempts, $job, $step_id)` (default 3) ·
`aipc_step_prompt($prompt, $step, $job)` · `aipc_messages($messages, $job, $step)` ·
`aipc_system_prompt($system, $job)` · `aipc_post_args($post_args, $job)`
