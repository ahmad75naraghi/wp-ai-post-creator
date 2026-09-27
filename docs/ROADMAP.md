# Roadmap & Product Decisions

Where AI Post Creator goes next — agreed with the product owner. Status at
**v1.5.0** (all shipped work lives in PR #1).

## Version plan

### 1.6 — Infrastructure & quality *(recommended next)*

The groundwork everything else builds on. Do these before more features.

| # | Item | Why / scope |
|---|---|---|
| 1 | **CI with GitHub Actions** | Run `lint.js` + full e2e (php-wasm) on every push/PR. The suite is already self-contained — the workflow mostly installs Node + Python, builds the workspace per `tests/e2e/README.md` and fails on any `false` boolean leaf or PHP warning. Cache `node_modules` + the WordPress tarball. |
| 2 | **Custom DB table for jobs** | Jobs currently live in the `aipc_jobs` option (last 30, whole-blob rewrite per step, autoload off). Move to a real table (`{$wpdb->prefix}aipc_jobs`: id, created, status, mode, source, post_id, topic JSON, payload JSON, indexes on status/created) **with schema versioning** (`aipc_schema_version` option + migration routine on upgrade). Keep a thin compatibility layer (`get_job()/get_all_jobs()`). Add scheduled pruning of old jobs. |
| 3 | **True background execution** | Today the console drives steps from the browser; only cron runs are self-contained. Integrate Action Scheduler (or a cron-loop runner) so a job keeps running server-side after the tab closes; the console becomes a viewer (poll the same `client_state()`). Keep per-step execution + the lock transient — just change the driver. |
| 4 | **SSRF hardening + REST rate limiting** | `base_url` and `source_sites` accept any http(s) host: block private/loopback IP ranges, add an optional outbound allowlist filter, and rate-limit the agent REST endpoints (e.g. `rest_authentication_errors`-based cap per user). See SECURITY.md "Known hardening roadmap". |

### 1.7 — Content & SEO

| # | Item | Notes |
|---|---|---|
| 5 | **Topic clusters (pillar–cluster)** | Plan topics around a pillar article; internal linking becomes intentional (pillar ↔ cluster) instead of only "recent posts". Needs a link-graph helper and new plan-prompt contract. |
| 6 | **Topic queue + content calendar** | A queue of topics (one per line / CSV) that schedule entries consume in order; show planned vs published on a calendar view. |
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
