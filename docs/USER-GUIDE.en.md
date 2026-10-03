# AI Post Creator — Complete User Guide (v1.18.0)

From installation to fully automated AI content — step by step.
Persian edition: [USER-GUIDE.fa.md](USER-GUIDE.fa.md)

---

## 1. Introduction

💡 Most sections of the plugin's admin pages carry a small **"?"** icon — click it to expand a short explanation of what that section does. This guide covers all of them in depth.

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
| Purpose *(new in 1.8)* | What the connection is used for: **chat & images** (default), **chat only** or **images only** |
| Image route *(new in 1.9)* | **Automatic** (images endpoint, then chat), **Images endpoint** (OpenAI-style) or **Chat completions** (Gemini/OpenRouter-style gateways — set an image-capable model such as `gemini-2.5-flash-image` as the Image model) |
| Image delivery *(new in 1.8.1)* | **Automatic** (base64 or link) or **Force base64** — the image bytes arrive inside the API response and are decoded locally; a link-only response fails over to the next image connection |
| Priority *(new in 1.8)* | 1–999, lower = tried first — after 3 failed attempts in a row the run switches to the next matching connection |
| Enabled *(new in 1.9.3)* | untick (or use the Disable link in the list) to keep the connection saved but exclude it from every run — pools, fallback chains and the default choice all skip it |
| Temperature / max tokens / timeout | Fine-tuning (defaults are sensible) |

- The **default** connection is used for every step unless you assign a chain.
- Use **Test connection** before saving.
- **Create more than one connection** so you can build fallback chains (section 8).

**Separate image server (new in 1.8):** add a connection with purpose
*images only* (e.g. an OmniRoute/gateway that hosts your image model) and
set your text provider to *chat only*. Text steps then use the chat pool
and the featured-image step the image pool — each ordered by priority,
with automatic failover after 3 errors per connection. Explicit per-step
chains (section 8) always take precedence over these automatic pools.

---

## 4. Site settings

Path: **AI Post Creator → Settings**

| Setting | Meaning |
|---|---|
| **Site prompt** ⭐ | The most important setting: what your site is about, its goal, audience and voice. Topics, categories and titles are built from it |
| **Research source sites** | Optional — one URL per line (max 8). The agent reads each site's RSS feed while planning and grounds the topic and facts in their latest articles |
| Content language | 12+ languages incl. Persian (defaults to the site locale) |
| Default tone / length | Tone (professional, friendly, …) and length (short ~600 / medium ~1,200 / long ~2,200 words) |
| Image / TOC / FAQ | Per-run defaults — the image size offers presets plus a free “Custom…” width×height (new in 1.12.1) |
| Default image prompt *(new in 1.10)* | Appended to every generated image prompt — use it for a consistent style (palette, art direction) across all featured images |
| Stock photo fallback *(new in 1.18)* | When every AI image connection fails, fetch a free CC-licensed photo from Openverse matching the article subject — no API key; the photo credit is saved in the attachment caption |
| Default featured image *(new in 1.18)* | Media-library ID or URL used as the guaranteed last resort so **every post gets an image**; an external URL is imported once and reused |

> 🪜 **The image rescue ladder (new in 1.18):** ① if the gateway says the
> configured image model doesn't exist, the plugin reads the gateway's own
> model list, picks an image-capable one and retries (cached a day);
> ② the other image connections and the chat-image route are tried;
> ③ the Openverse stock fallback (if enabled); ④ the default featured
> image. Only when all four rungs are empty does a post go out without a
> picture.
| Source links *(new in 1.12)* | Whether articles may link to the research source sites — off = sources are used for facts only, links stripped |

> 🔗 Since 1.12 every internal link target is used at most once per article —
> duplicate internal links are unwrapped automatically.

> 🔍 **Debugging failed steps or images (new in 1.11):** Settings → Advanced →
> "API trace log" records every AI request and response (prompts, replies,
> full error bodies, status, timing, with job/step/connection/attempt) into a
> protected file you can download and inspect — keys redacted, base64 omitted.
> Turn it off again after debugging.

> ✍️ Persian half-spaces (نیم‌فاصله) are preserved automatically since 1.10:
> the common patterns are repaired in AI output and the character is stored
> as `&zwnj;` in post content so no editor can strip it.
> 🖼 The posts list (Posts → All Posts) also gets a **Regenerate AI image**
> row action — one click builds and sets a fresh featured image for that post.

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

**Background execution (new in 1.6):** the job runs on the server itself —
you can close the browser tab and it keeps going. The console is just a live
viewer, and it warns you if progress stalls for a while (for example when
WP-Cron is not running on your site).

**Internal linking:** while planning, the agent looks at your existing related
posts and weaves up to 4 internal links naturally into the content — no action
needed from you.

---

## 5.5. Draft review inbox (new in 1.6)

Path: **AI Post Creator → Review drafts**

Every AI-generated draft — manual or scheduled, new or rewritten — is
collected on one page: title, status, word count, origin (manual / scheduled /
rewrite) and the last-modified time. For each draft:

- ✎ **Edit post** — open the WordPress editor
- 👁 **Preview** — see the rendered result in your browser
- 🚀 **Publish** — one-click publishing with a confirmation (users who can
  publish only)
- ♻️ **Rewrite** — send that very post straight to the Rewrite page

Nothing goes live until you say so — the plugin's standing policy.

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

### 📋 Topic queue (new in 1.7)

On the same Schedule page, see the **Topic queue** card:

- Schedule entries with **"Take the topic from the queue"** consume the oldest
  pending topic when they fire; if the queue is empty they fall back to the
  entry's fixed topic (or the site prompt).
- **Add by hand:** type several topics, one per line, and press "Add to queue".
- **Smart suggestions:** the **"Suggest topics from my sources"** button reads
  the latest headlines from the research sources configured in Settings,
  strips source-name suffixes and drops duplicates (against the queue *and*
  your recent posts) — you tick the ones you like and they join the queue.
- Used topics are remembered so they are never suggested twice; "Clear queue"
  only removes the pending ones.

> The queue is also visible from Bale — send the «صف» command (section 9).

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

## 9. Bale / Telegram bot

Path: **AI Post Creator → Bale / Telegram** *(its own page since 1.15 —
with the full six-step setup guide built right in; the Schedule page
links there)*

**Platform:** pick Bale or Telegram in the settings — both use the same
bot API, so everything below works identically on either. Note that the
server must be able to reach the chosen API (from inside Iran Telegram
is usually blocked; Bale works).

1. Create a bot with [@Bot_Father](https://web.bale.ai/) in Bale and copy its token
2. Paste it into "Bot token" (write-only — leave empty to keep the current one)
3. **Recipients:** one per line — a person's numeric chat ID or a @channel
4. Send any message to your bot, then press **Auto-detect chat ID**
5. **Send test message** checks every recipient at once

After every generated post (manual or scheduled) every recipient gets a
channel-ready message — `🔻title`, `🌱🌱summary🌱🌱`, a "read the full
article" line with 👇👇👇 and the link — with the **featured image sent
above it**. If the post has no featured image, the **default notification
image** (new in 1.9.2, set its URL in the same section) is used instead;
leave it empty to send text-only. Per-recipient delivery results are
written to the job log.

**Periodic report:** daily or weekly — an activity summary (jobs, success/fail,
drafts, tokens, usage per connection) at your chosen time/day.

### 💬 Two-way Bale commands (new in 1.7)

In the same Bale section, enable **"Accept commands from Bale chats"**. The
bot then checks for new messages roughly every 5 minutes and obeys only the
chat IDs configured on this page — messages from anywhere else are ignored
silently.

| Command | What it does |
|---|---|
| `نوشتن: a topic` | Starts a background draft run about that topic |
| `وضعیت` | Today's runs, drafts awaiting review and queue state |
| `آخرین` | The newest AI draft + its link |
| `انتشار` or `انتشار ۲` | Publishes the newest (or 2nd) draft — Persian digits work |
| `صف` | Pending topics in the queue |
| `راهنما` | The full command list |

- At most **20 posts per day** can be started from Bale (a cost guard).
- When the draft is ready you get the usual image + summary + link message;
  `انتشار` also fires the 🎉 published notification.
- English aliases work too: `/new <topic>`, `status`, `latest`, `publish`,
  `queue`, `help`.

### 🔘 Publish / Schedule buttons (new in 1.13)

When two-way commands are on, every draft notification arrives with two
buttons under it:

- **🚀 Publish now** — publishes the post immediately, dated right now.
- **⏰ Schedule** — the bot asks for the date; reply with one message:
  `1404/07/20 18:30` (Jalali), `2026-10-12 18:30` (Gregorian),
  `فردا 18:30` or `امروز 22:00` — Persian digits work, and leaving the
  time off means 09:00. The post is scheduled like a normal WordPress
  scheduled post and the bot sends the 🎉 notice when it goes live.
  Send `لغو` to cancel (the request also expires after 30 minutes).

### ⏱ One-step scheduling (new in 1.17)

Scheduling no longer needs a question-and-answer roundtrip:

- **One-tap presets** — pressing **⏰ Schedule** now shows four quick
  buttons (tonight 21:00, tomorrow 09:00, tomorrow 18:00, in two days
  09:00). One tap and the post is scheduled — no typing.
- **Just send a date** — send `فردا 18:30` or `1404/07/20 18:30` to the
  bot with no button pressed at all: the newest AI draft is scheduled
  right away.
- **Reply to a notification** — the fastest way: swipe/long-press the
  draft notification, choose *Reply*, and type only the date. Exactly
  that post is scheduled, even if newer drafts exist. (The bot remembers
  the last 100 notifications it sent.)

Already-published posts are protected — the bot refuses to reschedule
them and sends the permalink instead.

### ⚡ Instant replies — webhook (new in 1.16)

By default the bot reads messages via WP-Cron about once a minute, so a
button press can take a minute or more to answer (longer on quiet
sites). Turn on **Instant replies (webhook)** on the Bale / Telegram
page and save: the platform then pushes every message and button press
straight to your site and the bot answers within seconds. Requires the
site to be publicly reachable over HTTPS; turning the box off removes
the webhook and polling resumes automatically.

### 📱 Interactive menu (new in 1.14)

Send `منو` (or `/menu`) — or simply tap the buttons that now come with
`/start` and `راهنما`:

| Button | What it does |
|---|---|
| ✍️ New topic | The bot asks for the topic; your next message starts the draft |
| 📋 Topic queue | Lists pending topics — tap one to start writing it right away |
| 📑 Drafts | The 5 newest AI drafts — tap one for its action card (publish now / schedule) |
| 📊 Status | Today's runs, drafts and queue |
| ❓ Help | The command list |

Anything the bot doesn't understand gets a hint plus this menu, so you
can always tap your way out.

---

## 10. Update from Git (1.5.1 + 1.5.2)

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
- **Git connection (1.5.2):** under "Update settings" save the **GitHub repository** (`owner/name`), the **branch** (`main` by default, or a development branch) and a **Personal Access Token**:
  - a token is only needed for private repositories and higher rate limits — leave it empty for public repos;
  - the token is **write-only**: after saving it is never displayed again, and an empty field means "keep the stored one";
  - it is stored in a separate non-autoloaded option on your site only and is sent solely to github.com over HTTPS.
- **🔌 "Test connection"** validates the repository + branch + token together against GitHub and reports the **latest version** (with an empty token field, the stored token is tested).
- **"Check for updates now"** re-reads the version of the stored branch.
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

### ⏳ Jobs & Cron page (new in 1.17)

Path: **AI Post Creator → Jobs & Cron**

Everything still running or waiting in the background, with stop
buttons so nothing piles up:

- **Unfinished jobs** — agent runs that are running, queued or stopped
  on an error. *Cancel* stops one (its runner cron event is removed so
  it can never resume by itself); *Cancel all* clears the lot.
- **Pending publishes** — delayed auto-publishes created by the agent
  (*Cancel publish* keeps the post as a draft) and scheduled posts
  (*Back to draft* un-schedules them). Nothing is ever deleted.
- **Recurring plugin events** — the plugin's own cron heartbeat
  (schedule tick, bot poll, job runner), listed read-only for
  transparency.

---

## 12. Troubleshooting

| Problem | Fix |
|---|---|
| "No AI connection configured" | Connections → create one and mark it default |
| 401 / Unauthorized | Wrong API key or Base URL; the base usually ends with `/v1` |
| Timeout errors on steps | Raise the connection's request timeout; pick a lighter model |
| No featured image | Check the connection's image model; if the whole chain fails the post still completes without an image |
| "Invalid image model" in the trace | That model id doesn't exist at your gateway — open Connections and pick an image model from the model list (loaded live from the provider) |
| Jobs stuck at "running" on the image step | Usually a hanging image gateway. Since 1.17.1 image calls are capped at 180 s and fail fast after one timeout; also lower the connection's request timeout and fix the image model. Stop stuck jobs on the Jobs & Cron page |
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
Nothing happens — since v1.6 every job runs entirely on the server and the
console is only a viewer. Come back any time and you'll see the current
progress (or the finished result); interrupted cron runs also resume
automatically.

**Does it plagiarize?**
The copywriting pass targets originality, and research sources are for
inspiration/grounding only — the system prompt explicitly forbids copying
their sentences.

**What does uninstalling remove?**
Only if you enabled "Delete all plugin data on uninstall" in the settings are
all plugin data (options, jobs, post meta) removed; otherwise everything stays.

**Is it multisite-safe?**
Not tested; network activation is not recommended at this time.
