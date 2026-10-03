# Changelog

All notable changes to AI Post Creator are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) — versions follow the plugin header.

## [1.14.0] — 2026-10-03

### Added
- **Interactive Bale menu.** «منو» / `/menu` — and the buttons now
  attached to `/start` and «راهنما» — open a tappable menu:
  - **✍️ New topic** — the bot asks for the topic in chat; the next
    message starts a background draft run («لغو» cancels).
  - **📋 Topic queue** — the pending topics, each with its own button
    that starts writing it immediately and marks it used in the queue.
  - **📑 Drafts** — the 5 newest AI drafts, each opening an action
    card (title, date, link, status) with the publish-now / schedule
    buttons from 1.13.0.
  - **📊 Status** and **❓ Help** as buttons.
- Unknown input replies with the hint *plus* the menu, so nobody is
  ever stuck. Replies can now carry inline keyboards everywhere
  (`reply_parts` in the poll loop). The pending-question store
  handles both modes (publish date / topic). 13 new strings
  translated (710 msgids).

### Tests
- New `bale_menu` group (13 assertions): menu composition, unknown
  fallback, the full new-topic conversation (job really created),
  queue buttons → job + marked used, missing-topic safety, drafts
  list buttons, action card wiring, and menu-on-help — 62 groups /
  464 assertions total.

## [1.13.0] — 2026-10-03

### Added
- **Publish-now / Schedule buttons in Bale.** Every draft notification
  now carries an inline keyboard (when two-way commands are on):
  - **🚀 Publish now** — publishes immediately, stamped with the
    current date/time, and sends the 🎉 notice.
  - **⏰ Schedule** — the bot asks for a date in the chat; the next
    message is parsed as Jalali (`1404/07/20 18:30`), Gregorian
    (`2026-10-12 18:30`), Persian digits, or «فردا 18:30» /
    «امروز 22:00» (time optional, defaults to 09:00). The post is
    scheduled with WordPress's native `future` status, published on
    time by WP itself, and announced via `future_to_publish` →
    `aipc_post_published`. «لغو» cancels; requests expire after 30
    minutes; past dates are rejected.
- `callback_query` handling in the Bale poll (authorized chats only,
  with `answerCallbackQuery`); pending date requests live in the
  `aipc_bale_pending` option. 12 new strings translated (697 msgids).

### Tests
- New `bale_buttons` group (17 assertions): date parsing (Jalali ↔
  Gregorian conversion, Persian digits, relative days, invalid input),
  keyboard construction and gating, notification body carrying
  `reply_markup`, publish-now date stamping, the full schedule
  conversation (past rejected, bad format hint, cancel), and the
  future-publish hook — 61 groups / 451 assertions total.

## [1.12.1] — 2026-10-01

### Added
- **Custom image size.** Settings → Featured images now has a
  "Custom…" choice with a free width×height field (e.g. `800x600`,
  `×`/spaces normalized, strict `\d{2,4}x\d{2,4}` validation). The
  API client already retries without a size when a provider rejects
  it. 2 new strings translated (685 msgids).

### Verified
- **ZWNJ forensic pipeline test** (`zwnj_pipeline`, 10 assertions):
  provider JSON with `\u200c` and with raw bytes, `wp_kses_post` on
  the raw character and the `&zwnj;` entity, DB insert/read for title
  and content, `the_content` front-end filters, an editor-style
  re-save with kses filters active, and the entity armoring — all
  preserve the half-space. Conclusion: disappearing half-spaces
  originate in the provider output (fixed by the 1.12.0 glued-word
  repair), not in transit or on save.

### Tests
- New `zwnj_pipeline` (10) and `image_size_custom` (4) groups
  (60 groups / 434 assertions total).

## [1.12.0] — 2026-10-01

### Added
- **Source-links switch** (Settings → Advanced, default on). When
  disabled, `source_context()` omits the research-source URLs from the
  prompts (the model cannot link what it never sees) and
  `AIPC_Post_Builder::clean_links()` strips any remaining link to a
  configured source host from the final article.
- **One internal link per target.** `clean_links()` (applied on create
  and rewrite) keeps only the first `<a>` for each internal URL —
  later duplicates are unwrapped to their anchor text. External links
  are untouched.

### Improved
- **Half-space repair for glued words.** Some providers strip the ZWNJ
  entirely («حرفهای», «خانوادهها», «علاقهمندان»). `AIPC_Text` now
  repairs glued «ها/های/هایی» after joining letters and «ه + ای/مند/
  مندان/سازی/گذاری/بندی/ریزی», with an exception list (تنها، بها،
  اشتها، رها، بهسازی …) and a curated ه-stem dictionary for the
  ambiguous «…های» ending (حرفه‌ای vs حرف‌های). 3 new strings
  translated (683 msgids).

### Tests
- `text_zwnj` grew to 18 assertions (glued repairs + exceptions); new
  `link_policy` group (8 assertions)
  (58 groups / 420 assertions total).

## [1.11.0] — 2026-09-30

### Added
- **Full API trace log.** New `AIPC_Trace` class + "API trace log"
  switch under Settings → Advanced: every request/response exchanged
  with the AI providers is appended as a JSON line — prompt messages,
  model reply, complete error body, HTTP status, duration, and the
  job / step / connection / attempt context set by the agent's retry
  loop (image downloads are logged too). Secrets never leak: API keys
  and Bearer tokens are redacted, long base64 runs are collapsed to
  `[base64 omitted: N chars]`, single fields cap at 20 k chars. The
  file lives under `wp-content/uploads/aipc-logs/` with a random name
  and `.htaccess` protection, rotates at 8 MB (one older generation
  kept) and can be **downloaded** (both generations streamed
  chronologically) or **cleared** from the settings page. 8 new
  strings translated (680 msgids).

### Tests
- New `api_trace` e2e group (10 assertions): capture, context,
  redaction, no-key-leak, off-means-off, protected dir, clear
  (57 groups / 404 assertions total).

## [1.10.0] — 2026-09-30

### Added
- **Persian half-space (نیم‌فاصله) preservation.** New `AIPC_Text`
  helper: rule-based ZWNJ repair (a space after «می/نمی» and before
  «ها/های/هایی/تر/ترین» becomes a half-space; existing half-spaces are
  never touched, non-Persian text passes through). Applied to titles,
  content, excerpts and SEO meta on create/update — and in post
  content the character is stored as the `&zwnj;` HTML entity, which
  survives every editor round-trip (the classic editor is known to
  strip the raw U+200C character).
- **Default image prompt** (Settings → Featured images): a style
  suffix appended to every generated image prompt — art direction,
  palette, mood — for consistent featured images. Deduplicated when
  the model already echoes it.
- **Regenerate AI image** row action in the posts list: one click
  builds a fresh featured image for any post — title + SEO summary →
  image prompt (chat-capable chain) → picture (image-capable chain),
  with a success/error notice. New `AIPC_Agent::regenerate_thumbnail()`
  runs outside a job. 9 new strings translated (672 msgids).

### Tests
- New `text_zwnj` (10) and `image_defaults` (9) e2e groups; the
  rewrite content assertion now expects the `&zwnj;` entity
  (56 groups / 394 assertions total).

## [1.9.3] — 2026-09-30

### Added
- **Disable a connection without deleting it.** New "Enabled
  (participates in runs)" checkbox in the connection form, a Status
  column and a one-click **Enable/Disable** action in the connections
  list (disabled rows are dimmed). Disabled connections are skipped
  everywhere: the automatic purpose pools, explicit step fallback
  chains and the default-connection choice. Existing connections are
  treated as enabled after the update; the Prompts page marks disabled
  entries in the chain selector. 8 new strings translated (663 msgids).

### Fixed
- `all_for_ui()` no longer assumes every stored connection row has
  model fields (defensive `isset`).

### Tests
- New `conn_disable` e2e group (8 assertions): legacy backfill, pool /
  chain / default skipping, disable-all → no default, sanitize
  keep-vs-toggle semantics, UI flag exposure
  (54 groups / 375 assertions total).

## [1.9.2] — 2026-09-30

### Changed
- **Bale post notifications: channel-ready format.** Messages now read
  `🔻title` / blank line / `🌱🌱summary🌱🌱` / "Read the full article at
  the link below👇👇👇" / permalink — replacing the status intro/word
  count footer. The featured image is still sent above as the photo;
  the delayed-publish notification uses the same format (and can carry
  the image) too.

### Added
- **Default notification image** (Bale settings): a URL sent above the
  message when a post has no featured image — so notifications are
  never image-less unless you want them to be. Sanitized to http(s)
  only. 3 new strings translated (655 msgids).

### Tests
- New `bale_format` e2e group (8 assertions): format shape, read-more
  line, default-image fallback with caption, URL sanitizing; the
  `bale_traffic` expectations migrated to the new format
  (53 groups / 367 assertions total).

## [1.9.1] — 2026-09-30

### Improved
- **Attribution headers on every API request.** `HTTP-Referer` (site
  URL) and `X-Title` (site name) are now sent as recommended by
  OpenRouter — WAFs that distrust anonymous datacenter traffic get a
  proper identity. Note: a 403 "Access denied by security policy" from
  a provider's edge (Cloudflare) is an IP/geo policy decision on their
  side; if headers don't help, the WordPress server needs an allowed
  egress IP (host/proxy) or an accessible relay gateway.

### Added
- **`aipc_api_headers` filter** — add custom headers (organization ids,
  proxy auth, WAF tokens …) to every AI request. Receives the headers,
  the full URL and the connection data with the API key stripped.

### Tests
- New `api_headers` e2e group (4 assertions): Referer/X-Title/Bearer
  present, key never leaked to the filter (52 groups / 359 assertions).

## [1.9.0] — 2026-09-30

### Added
- **Second image-generation route: chat completions (Gemini/OpenRouter
  style).** Gemini-style gateways don't implement `/images/generations`
  (typical errors: "No credentials for image provider: openai",
  "returned no image data") — they generate pictures through a chat
  completion and return them as base64 `data:` URIs. New per-connection
  **Image route** option:
  - *Automatic* (default): images endpoint first, then the chat route
    by itself — existing setups keep working and gain the fallback;
  - *Images endpoint*: classic OpenAI behaviour only;
  - *Chat completions*: for Gemini-style gateways, with an
    image-capable model (e.g. `gemini-2.5-flash-image`) as Image model.
  The chat route (`AIPC_API_Client::image_via_chat()`) sends
  `modalities: ["image","text"]` (with a compatibility retry without
  it) and parses OpenRouter `message.images[]`, multimodal content
  parts, Gemini `inline_data` and `data:` URI content strings — all
  decoded locally, so it pairs naturally with *Force base64*.
  6 new strings translated (657 msgids).

### Tests
- `image_delivery` grew to 12 assertions: chat route returns bytes,
  the auto cascade (link-only endpoint → refused by Force base64 →
  chat route → bytes) and `image_api` sanitizing
  (51 groups / 355 assertions total).

## [1.8.2] — 2026-09-30

Robustness audit of the 1.8.x line — every failure path of the new
routing/delivery features was traced end-to-end (form → sanitize →
storage → agent chain → API client → media library).

### Fixed
- **URL-image download failures now fail over.** In *Automatic* delivery
  mode a failed download of a link-returned image silently skipped the
  featured image; it now throws into the step's retry/failover loop, so
  the next attempt or the next image connection gets its chance first.
  (When the whole chain fails, the step still skips gracefully — a run
  never dies because of the image.)
- **Model picker: re-loading models rebinds the search filter.** The
  filter listener kept closing over the first loaded list; a second
  "Load models" (e.g. after changing the base URL) now filters the
  fresh list.
- **Null-safe job composition**: a missing default connection can no
  longer trigger a PHP error when reading its chat model.

### Verified (no changes needed)
- Connection save handler forwards all new fields; `image_prompt` is
  correctly routed to the chat pool; explicit per-step chains still win;
  pre-1.8 connections normalize on read; e2e suite is deterministic
  across back-to-back runs (2× 51 groups / 352 assertions, zero PHP
  warnings); no wrong textdomains.

## [1.8.1] — 2026-09-30

### Added
- **"Image delivery" per connection: Force base64 mode.** Providers can
  return generated images as base64 (`b64_json`) or as temporary links.
  The new *Force base64* option demands the bytes inside the API
  response and decodes them locally (`AIPC_API_Client::decode_b64_image()`)
  — nothing depends on downloading expiring URLs, making image creation
  deterministic. A link-only response in this mode fails the attempt so
  the 3-strike priority failover (1.8.0) moves to the next image
  connection. Default stays *Automatic* (base64 or link, unchanged).

### Improved
- Base64 payloads are decoded defensively in every mode: `data:` URIs
  (also when returned in the `url` field), embedded whitespace/newlines,
  strict-then-lenient decoding. 5 new strings translated (651 msgids).

### Tests
- New `image_delivery` e2e group (9 assertions): sanitizing, decoder
  edge cases, force-b64 gets bytes from the mock, refuses a URL-only
  provider, auto mode still accepts URLs (51 groups / 352 assertions).

## [1.8.0] — 2026-09-30

### Added
- **Connection purposes: separate chat and image servers.** Every
  connection now carries a `purpose` — *chat & images* (default), *chat
  only* or *images only* — plus a numeric `priority` (1–999, lower =
  tried first). The featured-image step automatically uses the
  image-capable connections and every text step the chat-capable ones,
  so images no longer have to come from the same server as the text.
- **Priority failover.** Steps without an explicit connection chain
  (Prompts page) get an automatic chain: all matching connections in
  priority order. Each entry keeps its own retry budget (3 attempts,
  `aipc_step_attempts` filter) — after 3 consecutive failures the run
  switches to the next connection. New `AIPC_Connections::for_purpose()`
  + `aipc_connections_for_purpose` filter.
- Connections page: Purpose select + Priority field (with help
  tooltips), new Purpose/Priority columns in the list. 7 new strings
  translated to Persian (646 msgids).

### Compatibility
- Stored pre-1.8.0 connections are normalized on read (purpose
  `both`, priority `10`) — nothing to migrate, explicit per-step chains
  still take precedence over the automatic pools.

### Tests
- New `conn_routing` e2e group (7 assertions): sanitizing, pool
  filtering + ordering, agent auto-chains for image vs plan steps,
  legacy defaults (50 groups / 343 assertions total).

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
