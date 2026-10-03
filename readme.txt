=== AI Post Creator ===
Contributors: ahmad75naraghi
Tags: openai, ai, content-generator, seo, gpt, dall-e, multi-provider
Requires at least: 5.7
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.19.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent-style AI post generator for any OpenAI-compatible API — jobs run in the background on their own database table, unlimited AI connections with per-step fallback chains, per-step prompts, post rewriting, internal linking, research sources, a topic queue with smart suggestions, scheduled auto-publishing, a draft review inbox, complete logs and two-way Bale commands.

== Description ==

AI Post Creator turns your WordPress admin into an AI content agent. Configure your **site prompt** once (what your site is about, its goal and audience). Then, each run: the agent picks one of your **existing post categories**, invents a topic from the site prompt (or uses yours), writes the article, runs a **copywriting + SEO revision pass** over the whole draft, builds the **Rank Math summary**, generates a **featured image from the topic + summary**, and saves everything as a **draft** for your review — live in the agent console, with every step auto-retried until it passes.

It talks to **any OpenAI-compatible REST API** — OpenAI, OpenRouter, Groq, DeepSeek, Together, Ollama, LM Studio and more — and you can mix them freely:

* **Unlimited AI connections** — each with its own base URL, API key, chat model, image model, temperature, max tokens and timeout. One connection is the default.
* **Per-step routing** — assign every pipeline step its own connection: e.g. write with OpenAI and generate images with a different provider.
* **Per-step prompt templates** — every step's prompt is editable, with a documented placeholder table. Untouched prompts stay in "default" mode and are auto-improved on plugin updates.
* **Complete admin management panel** — connections manager, prompts & steps editor, and a logs panel with summary stats, usage per connection, full job history and per-job drilldown (step timings, every API call with model/tokens/duration/errors, console replay).

**New in 1.7.0 — topic queue + two-way Bale commands:**

* **Topic queue** — a FIFO bank of topics your schedules pull from, one per run, with smart suggestions pulled straight from your research sources' latest headlines (deduplicated against the queue and your recent posts)
* **Two-way Bale commands** — drive the plugin from inside Bale: «نوشتن: a topic» starts a draft run in the background, «وضعیت» / «آخرین» / «صف» report on today's runs, the newest draft and the queue, and «انتشار» publishes it — only the chat IDs you configured are obeyed

**New in 1.5.0 — rewrite, internal linking, auto-publish, fallback chains, research sources:**

* **Rewrite existing posts** — pick any old post and give it a full copywriting + SEO refresh: the agent analyzes it, rewrites the whole article (keeping the facts, links, status, author, slug and category), refreshes the SEO metadata and can attach a new featured image
* **Automatic internal linking** — while writing, the agent weaves links to your existing related posts into the new content (planned in the topic step, max 4 per article)
* **Scheduled auto-publishing** — posts are still created as drafts by default, but each run can optionally publish immediately or after a delay (15–10080 minutes)
* **Connection fallback chains** — assign several AI connections to a step; each one gets its own retry budget and the agent automatically switches to the next when one keeps failing (text and image steps alike)
* **Existing-post awareness** — the topic step sees your recent published titles and avoids duplicating them
* **Research source sites** — list up to 8 URLs; the agent reads their RSS feeds while planning and grounds the topic and facts in their latest articles

**New in 1.4.0 — recipients, reports, limit:**

* **Multiple Bale recipients** — any number of chat IDs (people or @channels); every post is delivered to all of them with per-chat results logged
* **Periodic Bale report** — daily/weekly activity summary (jobs, success/fail, drafts, tokens, usage per connection) at your chosen time/day
* **Daily scheduled-post limit** — cap automatic posts per day (0 = unlimited), with today's count shown on the Schedule page

**New in 1.3.0 — schedules + Bale:**

* **Automatic schedules (cron)** — any number of entries: local time, weekdays, fixed or auto-invented topic and full run options. The scheduler ticks every 15 minutes, catches up same-day, never double-fires, resumes interrupted runs and auto-retries failed ones. A "Run now" button starts any entry immediately in the live console.
* **Bale notifications** — after every generated post, message any Bale chat with the featured image + summary + link. Write-only bot token, automatic chat-ID detection, test button, delivery logged per job.

Your API keys stay on your own site.

**Pipeline (each step runs live, visible in the agent console):**

1. Category & topic selection — picks one of your existing categories and builds the topic from the site prompt (audience, intent, primary/secondary keywords)
2. Article outline (section count follows the chosen length)
3. Introduction
4. Every section individually (with context flow between sections)
5. Conclusion & call to action
6. Copywriting & SEO pass — the whole draft is revised for copy quality, keyword placement and originality
7. FAQ block with FAQPage JSON-LD schema (Google rich results)
8. SEO summary for Rank Math — meta title/description + focus keyword (also written to Yoast), English slug, excerpt, tags
9. Featured image built from the topic + summary (DALL·E 3 / gpt-image-1 compatible, skipped gracefully if unsupported)
10. Save as a draft — with category, tags, meta and thumbnail attached

**Highlights**

* Live agent console: progress bar, step checklist, timestamped log
* Every step is automatically retried up to 3 times before an error is raised; manual retry button; cancel anytime
* Per-run options: tone, length (short/medium/long), 12+ content languages (incl. Persian), FAQ/TOC/image toggles
* The result is always a draft — you review before anything goes live
* Token usage tracking per job, per connection and in aggregate
* Automatic post generation on a schedule (weekdays + times, per-entry options, optional daily cap)
* Bale message (image + summary + link) after every generated post — to any number of recipients, plus a daily/weekly activity report
* Works without PHP execution-time problems — one step per request
* RTL-friendly, full Persian (fa_IR) translation included
* Developer filters & hooks: `aipc_system_prompt`, `aipc_step_prompt`, `aipc_step_connection`, `aipc_messages`, `aipc_post_args`, `aipc_step_attempts`, `aipc_post_created`
* Automatic migration of 1.1 provider settings into a default connection
* Clean uninstall (opt-in data removal)

== Installation ==

1. Upload the `wp-ai-post-creator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Open **AI Post Creator → Connections**, add a connection (base URL + API key), click **Test connection** and **Load models from provider**. Add as many connections as you need — one image-focused provider can be added for the featured-image step.
4. Open **AI Post Creator → Settings** and write the **Site prompt** (what your site is about).
5. (Optional) Under **AI Post Creator → Prompts & Steps**, assign connections to steps and adjust prompt templates.
6. (Optional) Under **AI Post Creator → Schedule**, add automatic schedules (e.g. every day at 09:00) and configure Bale notifications (bot token + chat ID).
7. Go to **AI Post Creator** — optionally type a topic (empty = the agent invents one) and click **Generate post**.

Example base URLs:

* OpenAI: `https://api.openai.com/v1`
* OpenRouter: `https://openrouter.ai/api/v1`
* Groq: `https://api.groq.com/openai/v1`
* DeepSeek: `https://api.deepseek.com/v1`
* Ollama (local): `http://localhost:11434/v1`
* LM Studio (local): `http://localhost:1234/v1`

== Frequently Asked Questions ==

= Which models work? =

Any chat model your provider offers (gpt-4o, gpt-4o-mini, gpt-4.1, deepseek-chat, llama-3.1-70b-versatile, …). Click "Load models from provider" on the Connections screen to list them automatically.

= Can different steps use different providers? =

Yes. Create one connection per provider, then assign each step its connection under "Prompts & Steps". Steps without an assignment use the default connection.

= Does it publish immediately? =

Never. The agent always saves a draft so you can review the AI content first.

= Does it work with Ollama / LM Studio? =

Yes. Point a connection's base URL at your local server and use any dummy API key.

= How do the automatic schedules work? =

The scheduler ticks every 15 minutes (via WP-Cron) and fires every due entry — at most one full run per entry per day, with same-day catch-up. For precise timing, disable WP-Cron in wp-config.php and call wp-cron.php from a server cron job every 15 minutes.

= How do I set up Bale notifications? =

Create a bot with @Bot_Father in Bale, paste its token under AI Post Creator → Schedule, then either enter the chat ID of the recipient or press "Detect chat ID" (send any message to your bot first). Use "Send test message" to verify. Every generated post then sends the featured image, the summary and the link to that chat.

= Is my API key safe? =

Keys are stored in your own WordPress database and sent only to the provider you configured. They are never exposed to the browser, REST responses or the connections list, and the key field is write-only (leave it empty to keep the stored key).

= I updated from 1.1 — where did my API settings go? =

They were migrated automatically into a default connection under "AI Post Creator → Connections". Nothing needs to be re-entered.

== Changelog ==

= 1.19.1 =
* Fix: one article could get its featured image regenerated and its notification repeated (and even end up image-less) when a background runner outlived its lock — the runner lock now carries an owner token and a heartbeat that refreshes it before every outbound request, and a runner that truly lost its lock discards its changes instead of rewinding the job

= 1.19.0 =
* New: duplicate-request guard — the same article can never be written twice: every start path (console, REST, chat bot, scheduler) first checks that the topic isn't already being written by a running job, wasn't the subject of an article created in the last 30 days (filter aipc_duplicate_window), and isn't still waiting in the topic queue; topics are compared in a canonical form (half-spaces, Arabic letters, digit styles, case and extra spaces unified)
* New: "Allow duplicate topic" toggle on the new-post console deliberately overrides the guard
* Fix: double-published posts — a hanging provider request could let a second background runner re-run the final step and create + publish the same article twice; the finalize step is now idempotent, the runner lock outlives the longest request, and done-notifications are sent exactly once
* Fix: overlapping cron ticks can no longer fire the same schedule entry twice on the same day, and a queued topic that's already been written no longer stalls the queue

= 1.18.0 =
* New: image rescue ladder — posts need never go out without a featured image: a rejected image model id is automatically swapped for one the gateway actually offers (via its /models list, cached a day); if all AI generation fails, an optional Openverse stock-photo fallback fetches a free CC-licensed photo matching the article (no API key, attribution saved on the attachment); and a new "Default featured image" setting (media ID or URL) is the guaranteed last resort
* Improved: the image-prompt step also produces English stock-search keywords

= 1.17.1 =
* Fix: a hanging image gateway can no longer pin jobs at "running" for an hour — image requests cap their timeout at 180 seconds (filter aipc_image_timeout), skip the automatic timeout-retry, and a 15-minute circuit breaker fails fast after the chat-image fallback times out once

= 1.17.0 =
* New: one-step scheduling from chat — the «زمان‌بندی» button now offers one-tap times (tonight 21:00, tomorrow 09:00 / 18:00, in two days 09:00); sending a bare date («فردا 18:30») schedules the newest draft directly; replying to a draft notification with a date schedules exactly that post
* New: "Jobs & Cron" admin page — see every unfinished job, pending delayed publish, scheduled post and recurring plugin cron event, and stop any of them with one click
* Improved: already-published posts can no longer be rescheduled from chat

= 1.16.0 =
* New: instant replies via webhook — enable "Instant replies (webhook)" on the Bale / Telegram page and button presses («انتشار همین حالا» / «زمان‌بندی») and chat commands are answered within seconds; the platform pushes every update straight to a secret REST endpoint instead of waiting for the cron poll
* Improved: the polling fallback now runs every minute instead of every 5 (existing schedules are migrated automatically); polling pauses while the webhook is active
* The troubleshooting section explains the delay and the fix

= 1.15.0 =
* New: dedicated "Bale / Telegram" admin page with a six-step setup guide, the bot settings (moved from the Schedule page, which now links there), a capabilities overview and a troubleshooting reference
* New: Telegram support — the bot settings have a Platform selector (Bale or Telegram); both speak the same bot API, so notifications, commands, buttons and scheduling all work on either
* Fix: AIPC_Bale::recipients() no longer warns when given a partial settings array

= 1.14.0 =
* New: interactive Bale menu — send «منو» (or /menu, or just press a button under /start and راهنما): ✍️ new topic (the bot asks for it in chat), 📋 topic queue with a button per topic that starts writing it right away, 📑 latest drafts with per-draft action cards (publish now / schedule), 📊 status and ❓ help
* Unknown messages now reply with a hint plus the tappable menu, queue topics started from a button are marked used, and «لغو» cancels any open question

= 1.13.0 =
* New: Bale draft notifications now carry two inline buttons — "🚀 Publish now" publishes immediately with the current date, "⏰ Schedule" asks for a date in the chat (Jalali 1404/07/20 18:30, Gregorian 2026-10-12 18:30, Persian digits, or «فردا 18:30» / «امروز 22:00») and schedules the post natively; WordPress publishes it on time and the bot sends the usual 🎉 notice
* Buttons require two-way commands to be enabled (they arrive through the same poll); «لغو» cancels a pending schedule request

= 1.12.1 =
* New: custom image size — pick "Custom…" in Settings → Featured images and enter any width×height (e.g. 800x600); providers that reject the size automatically fall back to a size-less request
* Verified: a new 10-stage forensic test proves the Persian half-space survives every stage inside the plugin and WordPress (provider JSON both escaped and raw, kses, DB save/read, front-end filters, editor-style re-save) — when half-spaces disappear, the provider output itself is the source, which the glued-word repair from 1.12.0 fixes

= 1.12.0 =
* New: "Source links" switch (Settings → Advanced) — choose whether articles may link to your research source sites; when off, the model never sees the URLs and any leftover source link is stripped from the final article
* New: each internal link target is used at most ONCE per article — duplicate internal links are automatically unwrapped to plain text
* Improved: Persian half-space repair now also fixes GLUED suffixes (providers that strip the ZWNJ entirely): «حرفهای»→«حرفه‌ای», «خانوادهها»→«خانواده‌ها», «علاقهمندان»→«علاقه‌مندان», «نوشیدنیهای»→«نوشیدنی‌های», «آمادهسازی»→«آماده‌سازی» — with an exception dictionary (تنها، بها، اشتها …) so real words are never broken

= 1.11.0 =
* New: full API trace log (Settings → Advanced) — records every AI request and response (prompts, model output, complete error bodies, HTTP status, timing) with the job, step, connection and attempt number, into a protected downloadable log file
* API keys are redacted and base64 image data is collapsed automatically; the file rotates at 8 MB and can be downloaded or cleared with one click
* Perfect for diagnosing why image generation fails on some providers — the exact error body of every failed attempt is captured

= 1.10.0 =
* New: Persian half-spaces (نیم‌فاصله) are preserved and repaired — the common patterns (می/نمی + verb, ها/های/هایی/تر/ترین suffixes) are fixed rule-based in titles, content, excerpts and SEO meta, and the character is stored as the &zwnj; entity in post content so no editor can strip it
* New: "Default image prompt" setting — appended to every generated image prompt for a consistent visual style across all featured images
* New: "Regenerate AI image" action in the posts list — builds a fresh AI featured image for any post with one click (uses the chat chain for the prompt and the image chain for the picture)

= 1.9.3 =
* New: connections can be disabled without deleting them — an "Enabled" checkbox in the connection form plus a one-click Enable/Disable action and a Status column in the connections list
* Disabled connections are skipped everywhere: automatic purpose pools, explicit step fallback chains and the default-connection choice
* Existing connections stay enabled after the update; the Prompts page marks disabled entries in the chain selector

= 1.9.2 =
* Changed: Bale post notifications use a clean channel-ready format — 🔻title, 🌱🌱summary🌱🌱, a "read the full article" line with 👇👇👇 and the link; the featured image is sent above as before
* New: "Default notification image (URL)" in the Bale settings — sent above the message when a post has no featured image (leave empty for text-only)
* The delayed-publish notification now uses the same format and can carry the image too

= 1.9.1 =
* Improved: every API request now sends the attribution headers recommended by OpenRouter (HTTP-Referer = site URL, X-Title = site name) — friendlier to WAFs that distrust anonymous datacenter traffic
* New: aipc_api_headers filter to add custom headers (organization ids, proxy auth, WAF tokens) to all AI requests; the API key is never exposed to the filter

= 1.9.0 =
* New: second image-generation route — "Chat completions (Gemini/OpenRouter-style)". Gemini-style gateways don't serve /images/generations (errors like "No credentials for image provider: openai"); they return the picture as base64 inside a chat completion. The new per-connection "Image route" option supports that, and "Automatic" (default) tries the images endpoint first and falls back to the chat route by itself
* The chat route parses OpenRouter message.images, multimodal content parts, Gemini inline_data and data: URI content — always decoded locally (base64), which pairs perfectly with the "Force base64" delivery mode

= 1.8.2 =
* Hardening pass: a failed download of a link-returned image now retries and fails over to the next image connection instead of silently skipping the featured image
* Fixed: re-clicking "Load models" now rebinds the search filter to the fresh model list
* Fixed: a missing default connection can no longer cause a PHP error when composing a job

= 1.8.1 =
* New: "Image delivery" option per connection — "Force base64" demands the image bytes inside the API response and decodes them locally, so image creation never depends on downloading temporary links; if the provider only returns a link the attempt fails over to the next image connection
* Improved: base64 image payloads are decoded defensively in every mode (data: URIs, embedded whitespace), and data: URIs returned in the url field are decoded locally too

= 1.8.0 =
* New: every connection now has a Purpose (chat & images / chat only / images only) and a Priority number — featured images can be generated by a completely different server than the text
* New: automatic priority failover — steps without an explicit connection chain use every matching connection in priority order (lower number first); when one fails 3 attempts in a row, the run switches to the next one automatically
* Existing connections keep working unchanged (they count as "chat & images" with priority 10); explicit per-step chains on the Prompts page still win

= 1.7.5 =
* Improved: "Load models from the provider" now opens a visible, searchable model list under the Chat model field — type to filter, click to pick. Previously the models only fed the browser's invisible autocomplete, which looked like nothing happened

= 1.7.4 =
* Fixed: pasting a full endpoint URL as the base URL (e.g. .../v1/chat/completions) no longer breaks every request with errors like "Unknown API route: /v1/chat/completions/models" — well-known endpoint paths are stripped automatically and a successful connection test writes the corrected base URL back into the field

= 1.7.3 =
* Fixed: on non-English admins (e.g. Persian) none of the plugin's subpages loaded their styles or scripts — the Connections, Settings, Rewrite, Review, Prompts, Logs and Schedule pages appeared unstyled and buttons such as "Test connection" did nothing. Screen detection now keys off the stable page slug instead of the translated menu title
* Fixed: the Update page never loaded the plugin stylesheet, in any language

= 1.7.2 =
* Easier connections to gateways and routers (OmniRoute, OpenRouter & co.): a base URL without a path gets /v1 appended automatically, the connection test probes the /v1 variant when the first attempt fails and corrects the field for you, and error messages now say clearly when an address returned a web page (HTML) instead of an API response
* New setting "Allow private/LAN addresses" (Settings → Advanced) so a self-hosted gateway on another machine in your network works without code — localhost was always allowed, the SSRF guard stays on for everything else
* Admin UI polish: consistent page headers (the Schedule page finally has one), unified field alignment inside and outside the Options boxes, restyled tables with proper header rows, day-of-week pills on the Schedule form, topic-queue and contextual-help elements aligned with the plugin design language, all inline styles removed, focus states for every input
= 1.7.1 =
* Documentation refresh: architecture reference brought up to the current code (bootstrap flow, 9 admin pages, topic-queue and Bale-command data/hooks, full admin-post handler list), REST reference now documents the /topics/suggest and /topics/add endpoints, roadmap and agent notes reflect the shipped v1.7.0 state, README and user guides corrected (background execution and 1.7 features filed under the right versions)
* No functional changes

= 1.7.0 =
* Topic queue: a FIFO bank of topics on the Schedule page — schedule entries with "Take the topic from the queue" consume the oldest pending topic on every run (falling back to their fixed topic or the site prompt when the queue is empty), so automated runs never repeat themselves
* Smart topic suggestions: the "Suggest topics from my sources" button pulls fresh headlines from your configured research sources, strips source-name suffixes and drops duplicates against the queue and your recent posts (REST: /aipc/v1/topics/suggest + /topics/add)
* Two-way Bale commands: with "Accept commands from Bale chats" enabled the bot polls for new messages every ~5 minutes and obeys the configured chats only — نوشتن: a topic starts a background draft run (max 20/day), وضعیت reports today's runs, آخرین shows the newest draft, انتشار [n] publishes it (Persian digits accepted), صف lists the queue and راهنما shows the command list
* Processed update ids are persisted (last_update_id) so a command never runs twice; messages from unknown chats are ignored silently
* Fix: trimming multi-byte punctuation could split a UTF-8 sequence in suggested topics

= 1.6.0 =
* Background job execution: a self-rescheduling cron event (aipc_run_job) drives every job server-side with a 600 s budget and automatic re-arming; the agent console became a read-only viewer polling the new /aipc/v1/state endpoint, so closing the tab never stops a run
* Jobs live in a dedicated database table ({prefix}aipc_jobs) with automatic migration from the old option, light-row log queries, SQL counters for the scheduler and configurable retention (aipc_job_retention_days, default 90 days; 0 = keep forever) — with a transparent fallback to the option when the table cannot be created
* SSRF hardening: new outbound network guard (AIPC_Network) validates every outbound URL — connection base URLs, research source sites and provider-returned image URLs; private/reserved IP ranges are blocked, loopback stays allowed for local LLMs (Ollama, LM Studio), and filters (aipc_outbound_allowlist, aipc_allow_private_hosts, aipc_allow_loopback) tune the policy
* REST rate limiting per user and per minute on /start, /step and /state (30/240/300; HTTP 429); adjustable via the aipc_rest_rate_limit filter
* New "Review drafts" page (edit_posts): all AI-generated drafts with status, word count, origin and modified time — edit, preview, one-click publish and rewrite-again actions
* CI with GitHub Actions: PHP/JS syntax lint, translation completeness and the full php-wasm e2e suite (WordPress 6.7.1 on SQLite) on every push and pull request
* Fix: deleting a connection now also cleans it out of every per-step fallback chain (and legacy single-connection step configs)

= 1.5.2 =
* Git connection settings: repository, branch and Personal Access Token (write-only, stored in a non-autoloaded option) — configurable right on the Update from Git page
* Connection test button checks the repository, branch and token together against GitHub and reports the latest version
* Private repositories supported: with a token, version checks hit raw.githubusercontent.com authenticated and downloads use the api.github.com zipball endpoint; without a token the public codeload URL is used

= 1.5.1 =
* New admin page "Update from Git": download the latest files straight from the GitHub repository and replace the plugin in place — version check (with a downgrade guard and a force-reinstall option), automatic backup of the previous version to wp-content/aipc-backups, atomic swap with automatic rollback on failure, and cleanup of stale files

= 1.5.0 =
* Rewrite mode: analyze + full revision of an existing post in place (status, author, slug and category preserved; tags appended; new featured image optional)
* Automatic internal linking to related existing posts while writing (planned in the topic step, capped at 4 links)
* Publish modes for every run: draft (default), publish immediately, or publish after a delay of 15–10080 minutes via a scheduled event
* Connection chains per step: ordered fallback list, per-connection retry budget, automatic switching on repeated failure; image steps degrade gracefully when the whole chain fails
* Topic planning sees the recent published posts and avoids duplicating them
* Research source sites (up to 8): RSS feeds are read during planning and ground the topic and facts
* New admin page: Rewrite post (post picker + live agent console); Prompts & Steps uses a multi-select chain; Schedule entries have publish settings
* Bale notifications distinguish created/rewritten/published posts, and delayed publishing notifies every chat when the post goes live

= 1.4.0 =
* Multiple Bale recipients: any number of chat IDs (people or @channels), per-chat delivery results in the job log
* Periodic Bale report (daily/weekly): jobs, success/fail, drafts, tokens and per-connection usage at a chosen time/day
* Daily limit for scheduled posts (0 = unlimited) with today's count on the Schedule page
* REST bale/test now tests every recipient at once

= 1.3.0 =
* Automatic schedules: any number of entries (time, weekdays, topic, run options), 15-minute cron tick with same-day catch-up and no double-firing
* "Run now" button starts a schedule immediately in the live agent console
* Bale messenger notifications after every generated post (featured image + summary + link), with automatic chat-ID detection and test messages
* Failed scheduled runs are auto-retried (up to 3 ticks) and interrupted runs are resumed
* New admin page: Schedule & Notifications (entries, Bale settings, cron status)
* Job logs show the source (scheduled vs manual) and Bale delivery results

= 1.2.0 =
* Unlimited AI connections (base URL, key, chat/image models, temperature, max tokens, timeout) with a default connection
* Per-step connection routing — e.g. text steps with one provider, images with another
* Editable per-step prompt templates with placeholder documentation and reset-to-default
* New admin panel: Connections, Prompts & Steps, and a complete Logs panel (summary stats, usage per connection, job history, per-call API details, console replay)
* Legacy provider settings migrate into a default connection automatically
* Job history increased to 30 with aggregate stats

= 1.1.0 =
* Site prompt: the agent invents topics from your site's context
* The agent now picks one of the existing post categories
* New copywriting + SEO revision pass over the whole draft
* Rank Math summary step (title, description + focus keyword)
* Featured image prompt built from the topic + summary
* Automatic per-step retries (up to 3 attempts)
* Posts are always saved as drafts

= 1.0.0 =
* Initial release: agent pipeline, live console, REST API, SEO meta, FAQ schema, featured images, Persian translation.
