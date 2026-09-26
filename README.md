# 🤖 AI Post Creator — Agent-Style AI Post Generator for WordPress

**Plugin Name:** AI Post Creator
**Version:** 1.2.0 · **Requires:** WordPress 5.7+ · PHP 7.4+ · **License:** GPL v2 or later

---

## فارسی — معرفی

**AI Post Creator** یک افزونهٔ فوق‌حرفه‌ای و «ایجنت‌مانند» برای وردپرس است که با اتصال به هر سرویس API هوش مصنوعیِ سازگار با OpenAI (OpenAI، OpenRouter، Groq، DeepSeek، Ollama، LM Studio و…)، یک پست کامل را **از صفر تا صد** تولید می‌کند:

ابتدا **پرامپت سایت** (سایت چیست، درباره چیست، هدف و مخاطبانش کدامند — در تنظیمات) و **دسته‌بندی‌های موجود نوشته‌ها** را می‌خواند، یکی از دسته‌بندی‌ها را انتخاب می‌کند و موضوع را بر اساس پرامپت سایت می‌سازد (یا از پیشنهاد شما استفاده می‌کند). بعد محتوا را می‌نویسد، یک **پاس کپی‌رایتینگ و سئو** روی کل متن اجرا می‌کند، **خلاصه سئو برای Rank Math** می‌سازد، از روی موضوع و خلاصه **تصویر شاخص** تولید می‌کند و نتیجه را همیشه به‌صورت **پیش‌نویس** ذخیره می‌کند — تمام مراحل به‌صورت زنده در یک **کنسول ایجنت** نمایش داده می‌شوند و در هر مرحله در صورت خطا، به‌طور خودکار تا سه بار دوباره تلاش می‌شود.

### 🆕 جدید در نسخهٔ ۱.۲.۰ — چند اتصال، چند هوش مصنوعی

- 🔌 **اتصال‌های نامحدود**: هر تعداد سرویس‌دهنده (مثلاً OpenAI برای متن + یک سرویس تصویر دیگر) با نشانی، کلید، مدل گفتگو و مدل تصویر، دما، سقف توکن و مهلتِ جداگانه تعریف کنید — یکی **پیش‌فرض** است.
- 🧩 **تخصیص اتصال به هر مرحله**: در صفحهٔ «پرامپت‌ها و مراحل» تعیین کنید متن‌ها با چه اتصالی نوشته شوند و **تصویر شاخص با کدام سرویس تصویر** ساخته شود (پیش‌فرض همه: اتصال پیش‌فرض).
- ✏️ **پرامپت اختصاصی هر مرحله**: قالب پرامپت هر ۱۱ مرحله قابل ویرایش است؛ اگر دست نزنید در حالت «پیش‌فرض» می‌ماند و با به‌روزرسانی افزونه بهبود می‌یابد. جدول جای‌نگهدارها (placeholders) کنار هر مرحله است.
- 📊 **پنل گزارش‌های کامل**: خلاصهٔ کلی (کارها، موفق/ناموفق، توکن مصرفی)، **مصرف به تفکیک هر اتصال**، فهرست کارها با صفحه‌بندی و صفحهٔ جزئیات هر کار شامل زمان‌بندی مراحل، **تمام فراخوانی‌های API** (اتصال، مدل، توکن پرامپت/تولید، مدت، خطا) و بازپخش کامل کنسول.
- 🧹 مهاجرت خودکار: تنظیمات قدیمی (نشانی/کلید) هنگام به‌روزرسانی به یک اتصال پیش‌فرض تبدیل می‌شود.

ویژگی‌ها:

- 🎯 **پرامپت سایت**: هویت و هدف سایت را یک بار تعریف کنید؛ ایجنت همیشه on-brand می‌نویسد
- 🗂 **انتخاب خودکار دسته‌بندی** از بین دسته‌بندی‌های موجود نوشته‌ها
- 💡 **موضوع خودکار**: فیلد موضوع اختیاری است — خالی بگذارید تا ایجنت خودش موضوع بسازد
- 🧠 پایپ‌لاین ۱۲ مرحله‌ای با لاگ زنده و نوار پیشرفت
- ✍️ **پاس کپی‌رایتینگ و سئو**: بازنویسی کل متن از نظر اقناع، سئو و اصالت (کپی‌رایت)
- 🔎 **خلاصه Rank Math**: عنوان و توضیحات متا + کلیدواژه کانونی (همچنین Yoast)، نامک انگلیسی، چکیده، برچسب‌ها
- ❓ بلوک پرسش‌های متداول + اسکیمای FAQPage (نتایج غنی گوگل)
- 📑 فهرست مطالب لینک‌شده
- 🖼 تصویر شاخص **از روی موضوع + خلاصه** با dall-e-3 / gpt-image-1
- ↻ **تلاش خودکار مجدد** هر مرحله (۳ بار) قبل از اعلام خطا + دکمه تلاش دوبارهٔ دستی
- 📄 نتیجه **همیشه پیش‌نویس** است تا ابتدا بازبینی کنید
- 🌍 ۱۲+ زبان محتوا (از جمله فارسی) + ترجمهٔ کامل فارسی رابط و سازگاری با RTL
- ⏱ هر مرحله در یک درخواست جدا → بدون مشکل timeout؛ 📊 شمارش توکن؛ 🧹 حذف داده‌ها در uninstall (اختیاری)

## English — Overview

AI Post Creator turns the WordPress admin into an AI content agent. Set a **site prompt** once (what your site is about), and the agent picks one of your **existing post categories**, invents a topic that fits, writes the article, runs a **copywriting + SEO revision pass**, builds the **Rank Math summary**, generates a **featured image from the topic + summary**, and saves everything as a **draft** — with every step visible live and auto-retried until it passes.

**New in 1.2.0 — multi-AI, multi-connection:**

* **Unlimited connections** — define any number of OpenAI-compatible providers (each with its own base URL, API key, chat model, image model, temperature, max tokens and timeout); one is the **default**.
* **Per-step connection routing** — under *Prompts & Steps*, assign every pipeline step its own connection: write the article with OpenAI, generate the featured image with a different image provider, etc.
* **Per-step prompt templates** — every step's prompt is editable with a documented placeholder table. Leave it untouched and it stays in "default" mode (auto-improved on plugin updates); one click resets to default.
* **Complete logs panel** — run history with summary stats (jobs, success/fail, tokens), **usage per connection**, and a per-job drilldown: step timings, every API call (connection, model, prompt/completion tokens, duration, errors) and a full console replay.
* **Automatic migration** — existing single-provider settings become the default connection on update.

### The agent pipeline

```
┌───────────────────────────┐   ┌──────────┐   ┌───────┐   ┌───────────────┐
│ 1. Category + topic        │ → │ 2. Outline│ → │ 3. Intro│ → │ 4. Section × N │
│    (from the site prompt)  │   └──────────┘   └───────┘   └───────────────┘
└───────────────────────────┘        │                                  │
                                     ▼                                  ▼
                       ┌──────────────┐   ┌─────────┐   ┌──────────┐   ┌─────────┐
                       │ 5. Conclusion│ → │ 6. COPY- │ → │ 7. FAQ   │ → │ 8. Rank │
                       └──────────────┘   │  WRITING │   └─────────┘   │  Math   │
                                          │  + SEO   │                 │  summary│
                                          └─────────────┘              └─────────┘
                                                                        │
              ┌─────────┐   ┌──────────────────────┐                    │
              │ 11. Save │ ← │ 10. Featured image    │ ←─────────────────┘
              │  DRAFT   │   │ (topic + summary)     │
              └─────────┘   └──────────────────────┘
```

Every step runs in its own REST request (`POST /aipc/v1/step`) against **the connection assigned to that step**, and is **automatically retried up to 3 times** when it fails — the run only stops (with a manual retry button) when a step keeps failing. The browser console streams progress, logs, and the step checklist; you can **cancel** anytime.

### What each step produces

| Step | Output |
|---|---|
| 1. Category & topic | Picks one of your existing categories, invents the topic from the site prompt (+ title, audience, intent, primary & secondary keywords) |
| 2–5. Writing | HTML intro, each section (with continuity context), conclusion with CTA |
| 6. Copywriting & SEO pass | The whole draft is revised: sharper copy, keyword placement, originality (anti-boilerplate) — headings may be improved, structure preserved |
| 7. FAQ | 4–5 Q&A pairs + FAQPage JSON-LD schema |
| 8. Rank Math summary | Meta title/description written to Rank Math (title, description + focus keyword) & Yoast, English slug, excerpt, 5–8 tags |
| 10. Featured image | Image prompt built from the topic + the summary → the **image connection's** images API → media library |
| 11. Save | **Always a draft** with category, tags, meta & thumbnail attached |

## Installation

1. Copy the `wp-ai-post-creator` folder to `wp-content/plugins/` and activate the plugin.
2. Open **AI Post Creator → Connections** and add your first connection (base URL + API key):

| Provider | Base URL | Notes |
|---|---|---|
| OpenAI | `https://api.openai.com/v1` | gpt-4o, gpt-4o-mini, gpt-4o-mini…, dall-e-3 |
| OpenRouter | `https://openrouter.ai/api/v1` | Hundreds of models, one key |
| Groq | `https://api.groq.com/openai/v1` | Very fast Llama/Mixtral |
| DeepSeek | `https://api.deepseek.com/v1` | deepseek-chat |
| Together | `https://api.together.xyz/v1` | Open models |
| Ollama (local) | `http://localhost:11434/v1` | Any dummy API key |
| LM Studio (local) | `http://localhost:1234/v1` | Any dummy API key |

   Click **Test connection**, then **Load models from provider** and pick your models. Add as many connections as you like (e.g. a second one dedicated to image generation).
3. Open **AI Post Creator → Settings** and write the **site prompt** — describe your site (what it is, its goal, audience, voice). The agent uses this to pick the category and invent topics.
4. (Optional) Under **AI Post Creator → Prompts & Steps**, assign connections to steps and tweak prompt templates.
5. Go to **AI Post Creator** — optionally type a topic (or leave it empty to let the agent invent one from the site prompt) — and press **✦ Generate post**. The agent picks a category, writes, revises, summarizes, illustrates, and saves a **draft**.

## Admin pages

| Page | Capability | Purpose |
|---|---|---|
| **AI Post Creator** (top level) | `edit_posts` | Agent console — options, live terminal, step checklist, result card |
| **Connections** | `manage_options` | Add/edit/delete connections, set the default, test + load models |
| **Prompts & Steps** | `manage_options` | Per-step connection assignment + editable prompt templates with placeholder docs |
| **Logs** | `manage_options` | Summary stats, usage per connection, job history (paged), per-job drilldown with API-call details |
| **Settings** | `manage_options` | Site prompt + content defaults (language, tone, length, TOC/FAQ/image defaults, extras) |

## Architecture

```
wp-ai-post-creator/
├── wp-ai-post-creator.php          # Bootstrap, activation, cron, migration hook
├── uninstall.php                   # Opt-in data removal
├── includes/
│   ├── class-aipc-connections.php  # Connection registry (CRUD, default, sanitize, migration)
│   ├── class-aipc-steps.php        # Step registry + per-step config (connection, custom prompt)
│   ├── class-aipc-settings.php     # Content settings storage & sanitization
│   ├── class-aipc-api-client.php   # OpenAI-compatible HTTP client (chat/models/images)
│   ├── class-aipc-agent.php        # Orchestrator: jobs, steps, routing, calls log, stats
│   ├── class-aipc-post-builder.php # Post assembly, terms, SEO meta, media, schema
│   ├── class-aipc-rest.php         # REST routes (/aipc/v1/*)
│   ├── class-aipc-admin.php        # Menus, renderers, admin-post handlers
│   └── class-aipc-assets.php       # Asset loading & i18n for JS
├── admin/views/                    # new-post, connections, prompts, logs, log-detail, settings
├── assets/                         # admin-agent.js, admin-connections.js, CSS (admin + front)
└── languages/                      # POT + full fa_IR translation (po/mo)
```

**REST API** (`/wp-json/aipc/v1/`):

| Route | Method | Permission | Purpose |
|---|---|---|---|
| `/start` | POST | `edit_posts` | Create a job from a topic + options |
| `/step` | POST | `edit_posts` | Execute exactly one step, return state + new logs |
| `/cancel` | POST | `edit_posts` | Cancel a running job |
| `/retry` | POST | `edit_posts` | Reset the failed step and resume |
| `/connection/test` | POST | `manage_options` | Test a connection (saved id or raw values) |
| `/connection/models` | POST | `manage_options` | List models for a connection (saved id or raw values) |

Jobs are persisted in the DB (`aipc_jobs`, autoload off, last 30 kept, auto-cleaned daily) together with per-call API logs and aggregate stats (`aipc_stats`), so a refresh never loses progress and the Logs panel always has the full history. A transient lock guards against concurrent step execution.

## Developer hooks

```php
// Route a step to a different connection at runtime.
add_filter( 'aipc_step_connection', function ( $conn, $step ) {
    if ( 'copywrite' === $step ) {
        $conn = AIPC_Connections::get( 'c_your_strong_model' );
    }
    return $conn;
}, 10, 2 );

// Override a step's prompt template programmatically.
add_filter( 'aipc_step_prompt', function ( $template, $step, $args ) {
    return $template; // inspect/modify
}, 10, 3 );

// Adjust the system prompt sent with every agent request.
add_filter( 'aipc_system_prompt', function ( $prompt, $job ) {
    return $prompt . ' Always write in UK English.';
}, 10, 2 );

// Inspect/modify the full message array for any step.
add_filter( 'aipc_messages', function ( $messages, $job ) {
    // $messages = [ ['role' => 'system'|'user', 'content' => ...], ... ]
    return $messages;
}, 10, 2 );

// Adjust the post before insertion.
add_filter( 'aipc_post_args', function ( $postarr, $job ) {
    $postarr['post_type'] = 'page'; // e.g. generate pages instead
    return $postarr;
}, 10, 2 );

// Change the automatic retry count (default 3).
add_filter( 'aipc_step_attempts', function ( $attempts ) { return 5; } );
```

## Security & privacy

* Capabilities: agents need `edit_posts`; connections/prompts/logs/settings need `manage_options`. Anonymous REST calls get 401.
* All AI output is sanitized: content with `wp_kses_post`, titles/slugs/terms with the matching `sanitize_*` functions.
* API keys never leave the server (server-to-provider only) and are never returned by any REST response or the connections list.
* The API key field is write-only in the UI (leave it empty to keep the stored key).
* Uninstall removes options and post meta only when explicitly enabled in settings.

## Quality — tested end to end

The plugin ships with an end-to-end suite (WordPress 6.7 + SQLite via php-wasm, **two mock providers** — a chat host and a dedicated image host) covering the full pipeline through the real REST stack:

✅ 45+ checks pass — including: job lifecycle (start → all 12 steps → done), progress/manifest correctness, auto-invented topic from the site prompt, **AI-chosen category from the existing categories**, Persian content generation, TOC anchors, **copywriting/SEO revision pass applied**, FAQ + JSON-LD schema, Rank Math summary + focus keyword, SEO meta (plugin + Yoast + Rank Math), tags, featured image uploaded to the media library, draft-only saving, anonymous-request rejection (401), **per-step connection routing verified at the HTTP level (chat host for text steps, image host for the image step, correct Bearer key per host)**, **custom FAQ prompt actually sent to the provider**, connection test + model loading (saved and raw), logs/connections/prompts page rendering, connection & settings sanitization (key preservation, clamping), the fa_IR translation bundle loading in WordPress (incl. plural forms), cancel flow, provider-failure → 3 automatic retries → error state → manual retry succeeds, request-shape verification (endpoints, auth headers, models).

## Frequently asked questions

**Does it publish immediately?** — Never. The agent always saves a **draft** so you can review the AI content first.

**Can different steps use different providers?** — Yes. Create a connection per provider under *Connections*, then assign each step its connection under *Prompts & Steps* (unset steps use the default connection).

**Will long posts time out?** — No. Each step is a separate request; only a single section is generated per request.

**What if my provider has no image API?** — The image step is skipped gracefully with a log entry; the rest of the post is unaffected.

**Can I use a local model?** — Yes — Ollama and LM Studio work out of the box. Increase the connection's timeout for slower local models.

**I updated from 1.1 — where did my API settings go?** — They were migrated into a default connection under *AI Post Creator → Connections*. Nothing needs to be re-entered.

**Persian/RTL support?** — The admin UI ships with a complete `fa_IR` translation and RTL-aware styling; the content language is switchable per run (Persian is auto-detected as default on Persian sites).

---

© 2026 Ahmad Naraghi — GPL v2 or later.
