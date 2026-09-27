# Changelog

All notable changes to AI Post Creator are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) — versions follow the plugin header.

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
  `CODE_OF_CONDUCT.md`, this changelog, `docs/ARCHITECTURE.md`,
  `docs/USER-GUIDE.fa.md`, `docs/USER-GUIDE.en.md`, `docs/RELEASE-CHECKLIST.md`.

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
