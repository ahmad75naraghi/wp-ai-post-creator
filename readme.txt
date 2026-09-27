=== AI Post Creator ===
Contributors: ahmad75naraghi
Tags: openai, ai, content-generator, seo, gpt, dall-e, multi-provider
Requires at least: 5.7
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent-style AI post generator for any OpenAI-compatible API — unlimited AI connections with per-step fallback chains, per-step prompts, post rewriting, internal linking, research sources, scheduled auto-publishing, complete logs and Bale notifications.

== Description ==

AI Post Creator turns your WordPress admin into an AI content agent. Configure your **site prompt** once (what your site is about, its goal and audience). Then, each run: the agent picks one of your **existing post categories**, invents a topic from the site prompt (or uses yours), writes the article, runs a **copywriting + SEO revision pass** over the whole draft, builds the **Rank Math summary**, generates a **featured image from the topic + summary**, and saves everything as a **draft** for your review — live in the agent console, with every step auto-retried until it passes.

It talks to **any OpenAI-compatible REST API** — OpenAI, OpenRouter, Groq, DeepSeek, Together, Ollama, LM Studio and more — and you can mix them freely:

* **Unlimited AI connections** — each with its own base URL, API key, chat model, image model, temperature, max tokens and timeout. One connection is the default.
* **Per-step routing** — assign every pipeline step its own connection: e.g. write with OpenAI and generate images with a different provider.
* **Per-step prompt templates** — every step's prompt is editable, with a documented placeholder table. Untouched prompts stay in "default" mode and are auto-improved on plugin updates.
* **Complete admin management panel** — connections manager, prompts & steps editor, and a logs panel with summary stats, usage per connection, full job history and per-job drilldown (step timings, every API call with model/tokens/duration/errors, console replay).

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
