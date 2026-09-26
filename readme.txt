=== AI Post Creator ===
Contributors: ahmad75naraghi
Tags: openai, ai, content-generator, seo, auto-publish, gpt, dall-e
Requires at least: 5.7
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent-style AI post generator for any OpenAI-compatible API — from a site prompt and a chosen category to a complete, SEO-revised draft post, step by step.

== Description ==

AI Post Creator turns your WordPress admin into an AI content agent. Configure your **site prompt** once (what your site is about, its goal and audience). Then, each run: the agent picks one of your **existing post categories**, invents a topic from the site prompt (or uses yours), writes the article, runs a **copywriting + SEO revision pass** over the whole draft, builds the **Rank Math summary**, generates a **featured image from the topic + summary**, and saves everything as a **draft** for your review — live in the agent console, with every step auto-retried until it passes.

It talks to **any OpenAI-compatible REST API** — OpenAI, OpenRouter, Groq, DeepSeek, Together, Ollama, LM Studio and more. Your API key stays on your own site.

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
* Token usage tracking per job
* Works without PHP execution-time problems — one step per request
* RTL-friendly, full Persian (fa_IR) translation included
* Developer filters: `aipc_system_prompt`, `aipc_messages`, `aipc_post_args`, `aipc_step_attempts`
* Clean uninstall (opt-in data removal)

== Installation ==

1. Upload the `wp-ai-post-creator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Open **AI Post Creator → Settings**, fill in the **Site prompt** (what your site is about), your API base URL and API key, then click **Test connection**.
4. Go to **AI Post Creator** — optionally type a topic (empty = the agent invents one) and click **Generate post**.

Example base URLs:

* OpenAI: `https://api.openai.com/v1`
* OpenRouter: `https://openrouter.ai/api/v1`
* Groq: `https://api.groq.com/openai/v1`
* DeepSeek: `https://api.deepseek.com/v1`
* Ollama (local): `http://localhost:11434/v1`
* LM Studio (local): `http://localhost:1234/v1`

== Frequently Asked Questions ==

= Which models work? =

Any chat model your provider offers (gpt-4o, gpt-4o-mini, gpt-4.1, deepseek-chat, llama-3.1-70b-versatile, …). Click "Load models from provider" in the settings to list them automatically.

= Does it publish immediately? =

Never. The agent always saves a draft so you can review the AI content first.

= Does it work with Ollama / LM Studio? =

Yes. Point the base URL at your local server and use any dummy API key.

= Is my API key safe? =

It is stored in your own WordPress database and sent only to the provider you configured. It is never exposed to the browser or REST responses.

== Changelog ==

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
