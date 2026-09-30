# Roadmap & Product Decisions

Where AI Post Creator goes next — agreed with the product owner. Status at
**v1.7.0** (all shipped work is merged to `main` — PR #1 through v1.5.2,
PR #2 through v1.7.0).

## Version plan

### 1.6 — Infrastructure & quality ✅ *shipped in v1.6.0*

All four items landed (plus a draft review inbox and a fallback-chain cleanup fix):

| # | Item | Status |
|---|---|---|
| 1 | **CI with GitHub Actions** | ✅ `.github/workflows/ci.yml`: lint (PHP 7.4-target parse) + `node --check` + translations freshness + full e2e (php-wasm, WordPress 6.7.1/SQLite); cached `node_modules` + WordPress tarball; fails on any `false` boolean leaf or PHP warning. |
| 2 | **Custom DB table for jobs** | ✅ `AIPC_Job_Store` → `{$wpdb->prefix}aipc_jobs` (id, created/updated, status, mode, source, post_id, steps_total/steps_done, calls, tokens, topic, payload JSON; KEY status/created/source), `aipc_schema_version` + automatic migration from the old `aipc_jobs` option, compatibility layer on `AIPC_Agent` (`get_job` / `get_all_jobs` light rows / `get_jobs_since`), retention pruning (`aipc_job_retention_days`, default 90) and a legacy-option fallback (`aipc_jobs_table_enabled`). |
| 3 | **True background execution** | ✅ Self-rescheduling single cron event per job (`aipc_run_job` → `AIPC_Scheduler::run_job`) drives `execute_step()` under the existing transient lock with a 600 s budget then re-arms (30 s; 60 s on transient errors); the console became a **viewer** polling the new read-only `/aipc/v1/state` endpoint; the 15-min tick is a safety net that re-arms lost runner events. No Action Scheduler (decision D4). |
| 4 | **SSRF hardening + REST rate limiting** | ✅ `AIPC_Network` guard (http(s)-only, private/reserved/IPv4-mapped ranges blocked, loopback allowed for local LLMs; filters `aipc_outbound_allowlist`, `aipc_allow_private_hosts`, `aipc_allow_loopback`) wired into `AIPC_Connections::sanitize`, `AIPC_Settings::sanitize` (source_sites), the REST connection test and `AIPC_API_Client::download()`. Per-user per-minute REST rate limits on `/start` `/step` `/state` (filter `aipc_rest_rate_limit`, HTTP 429). |
| 5 | **Draft review inbox** *(added during implementation)* | ✅ "Review drafts" submenu (cap `edit_posts`): every `_aipc_generated` draft/pending post with status/word count/origin (manual/scheduled/rewrite)/modified time and quick actions (edit, preview, publish with nonce, rewrite-again deep link). |

### 1.7 — Content & SEO *(in progress — first slice shipped in v1.7.0)*

| # | Item | Notes |
|---|---|---|
| 5 | **Topic clusters (pillar–cluster)** | Plan topics around a pillar article; internal linking becomes intentional (pillar ↔ cluster) instead of only "recent posts". Needs a link-graph helper and new plan-prompt contract. |
| 6 | **Topic queue + content calendar** | ✅ *Queue shipped in v1.7.0*: FIFO topic bank (`AIPC_Topic_Queue`), schedule entries with `use_queue`, RSS suggestions (`/topics/suggest`), dedup memory. The **calendar view** (planned vs published) is still open. |
| 6b | **Two-way Bale commands** *(added during 1.7)* | ✅ *Shipped in v1.7.0*: 5-min `getUpdates` poll, authorized chats only — نوشتن / وضعیت / آخرین / انتشار / صف / راهنما, daily cap 20, `last_update_id` idempotency. |
| 7 | **Automatic old-post refresh** | Combine v1.5 rewrite with the scheduler: "find the N oldest/most-outdated posts and rewrite one per week" — needs an outdatedness heuristic (age, traffic, manual flag). |
| 8 | **E-E-A-T signals** | Author profile box, visible "reviewed/updated" dates, sources box for research-sourced articles. |

### 1.8 — Distribution & growth

| # | Item | Notes |
|---|---|---|
| 9 | **wp.org submission prep** | Guideline review (naming, escaping, i18n already strong), plugin-check action in CI, screenshots, dynamic `readme.txt` tags. |
| 10 | **Freemium / Pro** | License gate for premium features (candidates: clusters, multisite, priority support) + self-hosted update channel. Decide the boundary carefully — the agent core must stay free. |
| 11 | **SaaS-y UX** | Onboarding wizard (connection → site prompt → first post), chart dashboard, content style templates, settings import/export (JSON). |

### Backlog (smaller, slot anywhere)

- In-post image gallery (not just the featured image)
- Audio/podcast version of a post (TTS)
- Markdown export + cross-posting to social networks
- Custom HTML wrappers/templates for output
- Per-connection token budget & cost tracking
- Multisite compatibility pass

## Decision log (binding product principles)

| # | Decision | Context |
|---|---|---|
| D1 | **Drafts by default.** Publishing is always an explicit choice (`publish_mode` = now/delay). | Original product promise (v1.1); kept when auto-publish was added (v1.5). Never reintroduce a default non-draft flow. |
| D2 | **Per-step retries until pass** — 3 automatic attempts per connection (filter `aipc_step_attempts`) + manual retry button. | User requirement since v1.1; extended in v1.5 so *each connection in a chain* gets its own budget. |
| D3 | **Topic from the site prompt + existing categories**, server-validated (invalid category → retry). | v1.1 requirement, unchanged. |
| D4 | **No runtime dependencies, no build step.** Plain PHP 7.4+ / vanilla JS. | wp.org-friendly and zero-friction installs. |
| D5 | **Persian is a first-class language.** Every user-facing string translated (fa_IR) before release. | The primary user is Persian-speaking. |
| D6 | **API keys are write-only** — never in REST responses, logs, or the browser. | Security stance since v1.2. |
| D7 | **The e2e suite is the contract.** A release with a red suite is not a release. | 141/141 groups green at v1.5.0. |
| D8 | **Prompts live in the registry**, editable per step; stored-equal-to-default counts as "default". | Keeps prompts improvable by plugin updates. |

## History

| Version | Theme | Commit |
|---|---|---|
| 1.0.0 | Agent-style generation from scratch + live console | initial |
| 1.1.0 | Site prompt + categories, copywriting pass, Rank Math, featured image, retries, drafts | — |
| 1.2.0 | Multi-connection, per-step routing + prompts, complete logs | — |
| 1.3.0 | Cron schedules + Bale notifications | `aa29342` |
| 1.4.0 | Multiple Bale recipients, periodic reports, daily limit | `beda31b` |
| 1.5.0 | Rewrite mode, internal linking, auto-publish, fallback chains, research sources | `a327601` |
| 1.5.2 | Git self-updater | — |
| 1.6.0 | Jobs DB table, background runner, SSRF guard + rate limiting, review inbox, CI | — |
| 1.7.0 | Topic queue + RSS suggestions, two-way Bale commands | — |
