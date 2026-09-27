# AI Post Creator — Complete User Guide (v1.5.1)

From installation to fully automated AI content — step by step.
Persian edition: [USER-GUIDE.fa.md](USER-GUIDE.fa.md)

---

## 1. Introduction

**AI Post Creator** turns your WordPress admin into a content agent. Set it up
once, and the agent will:

1. Pick a fitting category from your **existing** post categories and invent a
   topic from your **site prompt** (or use the topic you suggest)
2. Outline the article and write it section by section
3. Run a **copywriting + SEO revision pass** over the whole draft
4. Build the SEO metadata (Rank Math / Yoast), tags, slug and excerpt
5. Generate and attach a **featured image**
6. Save the result as a **draft** (or publish it, per your choice)

Every step runs live in the agent console and is automatically retried up to 3
times on failure.

**Two main flows:**

- 🆕 **New AI Post** — from scratch to finished article
- ♻️ **Rewrite post** — refresh an existing post in place

---

## 2. Installation

**Requirements:** WordPress 5.7+, PHP 7.4+

1. Copy the `wp-ai-post-creator` folder into `wp-content/plugins/` (or upload
   the zip under Plugins → Add New)
2. Activate the plugin
3. A new **AI Post Creator** menu appears in your dashboard

Your API keys are stored only in your own site's database and are sent only to
the provider you configure.

---

## 3. AI connections (first step)

Path: **AI Post Creator → Connections**

Any OpenAI-compatible service works: OpenAI, OpenRouter, Groq, DeepSeek,
Together, Ollama, LM Studio…

| Field | Meaning |
|---|---|
| Name | Display label (e.g. "OpenAI text") |
| Base URL | e.g. `https://api.openai.com/v1` |
| API key | Write-only — leave empty to keep the stored key |
| Chat model | e.g. `gpt-4o-mini` — use "Load models" to list what the service offers |
| Image model | e.g. `dall-e-3` or `gpt-image-1` |
| Temperature / max tokens / timeout | Fine-tuning (defaults are sensible) |

- The **default** connection is used for every step unless you assign a chain.
- Use **Test connection** before saving.
- **Create more than one connection** so you can build fallback chains (section 8).

---

## 4. Site settings

Path: **AI Post Creator → Settings**

| Setting | Meaning |
|---|---|
| **Site prompt** ⭐ | The most important setting: what your site is about, its goal, audience and voice. Topics, categories and titles are built from it |
| **Research source sites** | Optional — one URL per line (max 8). The agent reads each site's RSS feed while planning and grounds the topic and facts in their latest articles |
| Content language | 12+ languages incl. Persian (defaults to the site locale) |
| Default tone / length | Tone (professional, friendly, …) and length (short ~600 / medium ~1,200 / long ~2,200 words) |
| Image / TOC / FAQ | Per-run defaults |

> 💡 Example site prompt: "A Persian home-gardening blog for beginners; warm,
> encouraging voice, focused on low-cost solutions for apartment dwellers."

---

## 5. Creating a new post

Path: **AI Post Creator → New AI Post**

1. **Topic (optional):** leave empty and the agent invents one from the site
   prompt — or write your own idea. The agent sees your existing posts and
   avoids duplicating them.
2. **Options:** tone, length, language, featured image, FAQ block (+ Google
   rich-results schema), table of contents.
3. **After creation (new in 1.5):** choose one of
   - 📝 **Keep as draft** (the default — always safe)
   - 🚀 **Publish immediately** when the run finishes
   - ⏱ **Publish later** — after 15–10080 minutes (up to a week)
4. Press **Generate post** and watch the console.

**The console** shows a progress bar, per-step status, a live log and token
usage. If a step fails after all retries, **Retry step** re-runs just that
step; **Cancel** stops the agent anytime.

**Internal linking:** while planning, the agent looks at your existing related
posts and weaves up to 4 internal links naturally into the content — no action
needed from you.

---

## 6. Rewriting an old post (new in 1.5)

Path: **AI Post Creator → Rewrite post**

1. Pick a post from the list (latest 100 by modification date)
2. Set the options (a **new** featured image, a fresh FAQ, new tone/language)
3. Press **Rewrite post**

The agent **analyzes** the post → **rewrites** the whole text with a
copywriting/SEO lens (facts and every link are kept, a relevant internal link
is added) → refreshes the SEO metadata → optionally generates a new featured
image.

**Important:** the post is **updated in place** — its status, author, slug and
category stay untouched; new tags are appended. Back up important content
before rewriting.

---

## 7. Schedules + auto-publishing

Path: **AI Post Creator → Schedule**

Each schedule entry has: a time (site timezone), weekdays, a topic (fixed or
empty = auto-invented), run options and — new in 1.5 — **publish settings**
(draft / immediately / after a delay).

- The scheduler wakes every 15 minutes, catches up same-day and never
  double-fires an entry.
- Interrupted runs are resumed automatically; failed runs are retried up to
  3 times.
- **Daily limit:** cap automatic posts at N per day (0 = unlimited); today's
  count is shown on the page.
- **Run now** starts any entry immediately in the live console.

> ⏱ **How "publish later" works:** the post is created as a draft as usual,
> then a secure scheduled event publishes it at the chosen time (only if it is
> still a draft) and sends a 🎉 Bale message to every recipient.

---

## 8. Connections per step: multiple AIs with automatic fallback

Path: **AI Post Creator → Prompts & Steps**

- Every step (topic planning, intro, sections, copywriting, SEO, image…) can
  have its own **connection chain**: hold Ctrl/Cmd to select several
  connections in order.
- **Fallback semantics:** each connection in the chain gets up to 3 attempts;
  if it keeps failing the agent automatically switches to the next one (you
  will see "switching to …" in the console log).
- If the **entire image chain** fails, the post continues without a featured
  image — nothing breaks.
- Leave empty to use the default connection.

**Real-world use:** text with OpenAI, images with a second service, and a
cheap backup like Groq for the critical steps — if the first one goes down,
your site keeps producing.

### Editing prompts
Each of the 13 steps' prompt templates is editable. Placeholders are wrapped
in `{{…}}` with a documentation table under each prompt. Untouched prompts
stay in "default" mode and improve with plugin updates; the "Reset" checkbox
restores the default.

---

## 9. Bale notifications

Path: **AI Post Creator → Schedule → "Bale notifications" section**

1. Create a bot with [@Bot_Father](https://web.bale.ai/) in Bale and copy its token
2. Paste it into "Bot token" (write-only — leave empty to keep the current one)
3. **Recipients:** one per line — a person's numeric chat ID or a @channel
4. Send any message to your bot, then press **Auto-detect chat ID**
5. **Send test message** checks every recipient at once

After every generated post (manual or scheduled): **featured image + summary +
link** goes to every recipient (with special ♻️ rewritten and 🎉 published
variants). Per-recipient delivery results are written to the job log.

**Periodic report:** daily or weekly — an activity summary (jobs, success/fail,
drafts, tokens, usage per connection) at your chosen time/day.

---

## 10. Update from Git (new in 1.5.1)

Path: **AI Post Creator → Update from Git**

- The page shows the installed version next to the version available on GitHub.
- **Check for updates now** refreshes the remote version for the selected branch.
- **Update from Git** updates the whole plugin in place:
  1. the repository snapshot (zip) is downloaded
  2. the plugin header is verified (corrupt packages are rejected)
  3. the current files are backed up to `wp-content/aipc-backups`
  4. the new files are swapped in atomically; on any failure the previous
     version is restored **automatically**
- Settings, connections, schedules and posts live in the database and stay untouched.
- **Branch:** `main` by default (stable releases); you can enter a development branch.
- To prevent accidental downgrades, updating from an older branch is blocked —
  unless you tick "Reinstall anyway".
- Files removed upstream are deleted too (a complete refresh, not an overlay).

> ⚠️ Note: if you installed via `git clone`, the `.git` folder is lost by this update.

## 11. Logs

Path: **AI Post Creator → Logs**

- **Overview:** job counts, success/fail, token usage
- **Usage per connection**
- **Job list** with pagination + a detail page per job: step timings, every API
  call (connection, model, tokens, duration, errors) and a full console replay
- Delete single jobs or clear all logs

---

## 12. Troubleshooting

| Problem | Fix |
|---|---|
| "No AI connection configured" | Connections → create one and mark it default |
| 401 / Unauthorized | Wrong API key or Base URL; the base usually ends with `/v1` |
| Timeout errors on steps | Raise the connection's request timeout; pick a lighter model |
| No featured image | Check the connection's image model; if the whole chain fails the post still completes without an image |
| Schedules never fire | Check that wp-cron is active (Tools → Site Health); low-traffic sites should add a real cron hitting `wp-cron.php` every minute |
| Delayed publish didn't happen | Same wp-cron condition — the event fires when cron wakes up |
| Output came out in English | Set "Content language" (global setting or per-run option) |
| Topics feel repetitive | Sharpen the site prompt; existing post titles are shown to the agent automatically |

---

## 13. FAQ

**Where is my API key stored?**
Only in your own site's database; it never appears in the browser, REST
responses or logs.

**What if I close the tab mid-run?**
The console drives steps from the browser; scheduled runs are self-contained
and interrupted cron runs resume automatically. (Full background execution is
on the 1.6 roadmap.)

**Does it plagiarize?**
The copywriting pass targets originality, and research sources are for
inspiration/grounding only — the system prompt explicitly forbids copying
their sentences.

**What does uninstalling remove?**
Only if you enabled "Delete all plugin data on uninstall" in the settings are
all plugin data (options, jobs, post meta) removed; otherwise everything stays.

**Is it multisite-safe?**
Not tested; network activation is not recommended at this time.
