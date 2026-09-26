# 🤖 AI Post Creator — Agent-Style AI Post Generator for WordPress

**Plugin Name:** AI Post Creator
**Version:** 1.1.0 · **Requires:** WordPress 5.7+ · PHP 7.4+ · **License:** GPL v2 or later

---

## فارسی — معرفی

**AI Post Creator** یک افزونهٔ فوق‌حرفه‌ای و «ایجنت‌مانند» برای وردپرس است که با اتصال به هر سرویس API هوش مصنوعیِ سازگار با OpenAI (OpenAI، OpenRouter، Groq، DeepSeek، Ollama، LM Studio و…)، یک پست کامل را **از صفر تا صد** تولید می‌کند:

ابتدا **پرامپت سایت** (سایت چیست، درباره چیست، هدف و مخاطبانش کدامند — در تنظیمات) و **دسته‌بندی‌های موجود نوشته‌ها** را می‌خواند، یکی از دسته‌بندی‌ها را انتخاب می‌کند و موضوع را بر اساس پرامپت سایت می‌سازد (یا از پیشنهاد شما استفاده می‌کند). بعد محتوا را می‌نویسد، یک **پاس کپی‌رایتینگ و سئو** روی کل متن اجرا می‌کند، **خلاصه سئو برای Rank Math** می‌سازد، از روی موضوع و خلاصه **تصویر شاخص** تولید می‌کند و نتیجه را همیشه به‌صورت **پیش‌نویس** ذخیره می‌کند — تمام مراحل به‌صورت زنده در یک **کنسول ایجنت** نمایش داده می‌شوند و در هر مرحله در صورت خطا، به‌طور خودکار تا سه بار دوباره تلاش می‌شود.

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
- 🔌 سازگار با هر endpoint سازگار با OpenAI + تست اتصال و بارگذاری خودکار فهرست مدل‌ها
- 🌍 ۱۲+ زبان محتوا (از جمله فارسی) + ترجمهٔ کامل فارسی رابط و سازگاری با RTL
- ⏱ هر مرحله در یک درخواست جدا → بدون مشکل timeout؛ 📊 شمارش توکن؛ 🧹 حذف داده‌ها در uninstall (اختیاری)

## English — Overview

AI Post Creator turns the WordPress admin into an AI content agent. Set a **site prompt** once (what your site is about), and the agent picks one of your **existing post categories**, invents a topic that fits, writes the article, runs a **copywriting + SEO revision pass**, builds the **Rank Math summary**, generates a **featured image from the topic + summary**, and saves everything as a **draft** — with every step visible live and auto-retried until it passes.

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

Every step runs in its own REST request (`POST /aipc/v1/step`) and is **automatically retried up to 3 times** when it fails — the run only stops (with a manual retry button) when a step keeps failing. The browser console streams progress, logs, and the step checklist; you can **cancel** anytime.

### What each step produces

| Step | Output |
|---|---|
| 1. Category & topic | Picks one of your existing categories, invents the topic from the site prompt (+ title, audience, intent, primary & secondary keywords) |
| 2–5. Writing | HTML intro, each section (with continuity context), conclusion with CTA |
| 6. Copywriting & SEO pass | The whole draft is revised: sharper copy, keyword placement, originality (anti-boilerplate) — headings may be improved, structure preserved |
| 7. FAQ | 4–5 Q&A pairs + FAQPage JSON-LD schema |
| 8. Rank Math summary | Meta title/description written to Rank Math (title, description + focus keyword) & Yoast, English slug, excerpt, 5–8 tags |
| 10. Featured image | Image prompt built from the topic + the summary → images API → media library |
| 11. Save | **Always a draft** with category, tags, meta & thumbnail attached |

## Installation

1. Copy the `wp-ai-post-creator` folder to `wp-content/plugins/` and activate the plugin.
2. Open **AI Post Creator → Settings**:
   - **Site prompt** — describe your site (what it is, what it's about, its goal, audience, voice). The agent uses this to pick the category and invent topics.
   - **Provider** — base URL + API key:

| Provider | Base URL | Notes |
|---|---|---|
| OpenAI | `https://api.openai.com/v1` | gpt-4o, gpt-4o-mini, gpt-4.1…, dall-e-3 |
| OpenRouter | `https://openrouter.ai/api/v1` | Hundreds of models, one key |
| Groq | `https://api.groq.com/openai/v1` | Very fast Llama/Mixtral |
| DeepSeek | `https://api.deepseek.com/v1` | deepseek-chat |
| Together | `https://api.together.xyz/v1` | Open models |
| Ollama (local) | `http://localhost:11434/v1` | Any dummy API key |
| LM Studio (local) | `http://localhost:1234/v1` | Any dummy API key |

3. Click **Test connection**, then **Load models from provider** and pick a model.
4. Go to **AI Post Creator** — optionally type a topic (or leave it empty to let the agent invent one from the site prompt) — and press **✦ Generate post**. The agent picks a category, writes, revises, summarizes, illustrates, and saves a **draft**.

## Architecture

```
wp-ai-post-creator/
├── wp-ai-post-creator.php          # Bootstrap, activation, cron
├── uninstall.php                   # Opt-in data removal
├── includes/
│   ├── class-aipc-settings.php     # Settings storage & sanitization
│   ├── class-aipc-api-client.php   # OpenAI-compatible HTTP client (chat/models/images)
│   ├── class-aipc-agent.php        # The orchestrator: jobs, steps, prompts, logging
│   ├── class-aipc-post-builder.php # Post assembly, terms, SEO meta, media, schema
│   ├── class-aipc-rest.php         # REST routes (/aipc/v1/*)
│   ├── class-aipc-admin.php        # Admin pages
│   └── class-aipc-assets.php       # Asset loading & i18n for JS
├── admin/views/                    # Agent console & settings pages
├── assets/                         # admin-agent.js, admin-settings.js, CSS (admin + front)
└── languages/                      # POT + full fa_IR translation (po/mo)
```

**REST API** (`/wp-json/aipc/v1/`):

| Route | Method | Permission | Purpose |
|---|---|---|---|
| `/start` | POST | `edit_posts` | Create a job from a topic + options |
| `/step` | POST | `edit_posts` | Execute exactly one step, return state + new logs |
| `/cancel` | POST | `edit_posts` | Cancel a running job |
| `/retry` | POST | `edit_posts` | Reset the failed step and resume |
| `/models` | GET | `manage_options` | Proxy provider model list |
| `/test` | POST | `manage_options` | Connection test |

Jobs are persisted in the DB (`aipc_jobs`, autoload off, max 8, auto-cleaned daily) so a refresh never loses progress, and a transient lock guards against concurrent step execution.

## Developer hooks

```php
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
```

## Security & privacy

* Capabilities: agents need `edit_posts`; publishing requires `publish_posts`; settings/models/test need `manage_options`. Anonymous REST calls get 401.
* All AI output is sanitized: content with `wp_kses_post`, titles/slugs/terms with the matching `sanitize_*` functions.
* The API key never leaves the server (server-to-provider only) and is never returned by any REST response.
* The API key field is write-only in the UI (leave it empty to keep the stored key).
* Uninstall removes options and post meta only when explicitly enabled in settings.

## Quality — tested end to end

The plugin ships with an end-to-end suite (WordPress 6.7 + SQLite via php-wasm, mock OpenAI-compatible provider) covering the full pipeline through the real REST stack:

✅ 35/35 checks pass — including: job lifecycle (start → all 12 steps → done), progress/manifest correctness, auto-invented topic from the site prompt, **AI-chosen category from the existing categories**, Persian content generation, TOC anchors, **copywriting/SEO revision pass applied** (revised headings replace the originals), FAQ + JSON-LD schema, Rank Math summary + **focus keyword**, SEO meta (plugin + Yoast + Rank Math), tags, featured image (from topic + summary) uploaded to the media library, draft-only saving, anonymous-request rejection (401), settings sanitization (incl. site prompt), robust JSON extraction (fences, chatter, tricky braces), cancel flow, provider-failure → **3 automatic retries per step** → error state → manual retry succeeds, request-shape verification (endpoints, Bearer auth header, model names).

## Frequently asked questions

**Does it publish immediately?** — Only if you select "Publish immediately" and your role may publish. The default is draft, so you can review AI content first.

**Will long posts time out?** — No. Each step is a separate request; only a single section is generated per request.

**What if my provider has no image API?** — The image step is skipped gracefully with a log entry; the rest of the post is unaffected.

**Can I use a local model?** — Yes — Ollama and LM Studio work out of the box. Increase "Request timeout" for slower local models.

**Persian/RTL support?** — The admin UI ships with a complete `fa_IR` translation and RTL-aware styling; the content language is switchable per run (Persian is auto-detected as default on Persian sites).

---

© 2026 Ahmad Naraghi — GPL v2 or later.
