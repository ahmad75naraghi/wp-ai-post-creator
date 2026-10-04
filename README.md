# 🤖 AI Post Creator — Agent-Style AI Post Generator for WordPress

**Plugin Name:** AI Post Creator
**Version:** 1.23.0 · **Requires:** WordPress 5.7+ · PHP 7.4+ · **License:** GPL v2 or later ([LICENSE](LICENSE))

---

## 📚 Documentation

| Document | Audience |
|---|---|
| [راهنمای کامل کاربر (فارسی)](docs/USER-GUIDE.fa.md) | کاربران افزونه |
| [Complete User Guide (English)](docs/USER-GUIDE.en.md) | Plugin users |
| [Architecture](docs/ARCHITECTURE.md) | Contributors — full system reference |
| [Development Cookbook](docs/COOKBOOK.md) | Contributors — step-by-step recipes for every common change |
| [REST API Reference](docs/REST-API.md) | Developers — endpoints, params, response shapes, curl examples |
| [Debugging Guide](docs/DEBUGGING.md) | Devs & power users — job logs, symptoms → causes → fixes |
| [Roadmap & Decisions](docs/ROADMAP.md) | Everyone — 1.6/1.7/1.8 plan, backlog, binding product principles |
| [AGENTS.md](AGENTS.md) | AI agents / developers — working rules, environment, verification |
| [Contributing](CONTRIBUTING.md) | Human contributors |
| [Getting Help / Support](SUPPORT.md) | Everyone — where to ask, how to report |
| [E2E Test Suite](tests/e2e/README.md) | How to run and extend the tests |
| [Release Checklist](docs/RELEASE-CHECKLIST.md) | Cutting a version |
| [Changelog](CHANGELOG.md) · [Security](SECURITY.md) · [Code of Conduct](CODE_OF_CONDUCT.md) · [License](LICENSE) | Everyone |

---

## فارسی — معرفی

**AI Post Creator** یک افزونهٔ فوق‌حرفه‌ای و «ایجنت‌مانند» برای وردپرس است که با اتصال به هر سرویس API هوش مصنوعیِ سازگار با OpenAI (OpenAI، OpenRouter، Groq، DeepSeek، Ollama، LM Studio و…)، یک پست کامل را **از صفر تا صد** تولید می‌کند:

ابتدا **پرامپت سایت** (سایت چیست، درباره چیست، هدف و مخاطبانش کدامند — در تنظیمات) و **دسته‌بندی‌های موجود نوشته‌ها** را می‌خواند، یکی از دسته‌بندی‌ها را انتخاب می‌کند و موضوع را بر اساس پرامپت سایت می‌سازد (یا از پیشنهاد شما استفاده می‌کند). بعد محتوا را می‌نویسد، یک **پاس کپی‌رایتینگ و سئو** روی کل متن اجرا می‌کند، **خلاصه سئو برای Rank Math** می‌سازد، از روی موضوع و خلاصه **تصویر شاخص** تولید می‌کند و نتیجه را همیشه به‌صورت **پیش‌نویس** ذخیره می‌کند — تمام مراحل به‌صورت زنده در یک **کنسول ایجنت** نمایش داده می‌شوند و در هر مرحله در صورت خطا، به‌طور خودکار تا سه بار دوباره تلاش می‌شود.

### 🆕 جدید در نسخهٔ ۱.۹.۰ — ساخت تصویر به سبک Gemini
- گزینهٔ **«مسیر تولید تصویر»** برای هر اتصال: مسیر دوم «گفتگو (Chat completions)» برای گیت‌وی‌هایی که تصویر را به‌صورت Base64 داخل پاسخ گفتگو برمی‌گردانند (OpenRouter، Antigravity و…). حالت «خودکار» اول اندپوینت تصویر را امتحان می‌کند و در صورت شکست خودش سراغ مسیر گفتگو می‌رود.

### 🆕 جدید در نسخهٔ ۱.۸.۱ — تصویر قطعی با Base64
- گزینهٔ **«دریافت تصویر»** برای هر اتصال: حالت «اجباری Base64» بایت‌های تصویر را داخل خودِ پاسخ API می‌گیرد و به‌صورت محلی تبدیل می‌کند — بدون وابستگی به لینک‌های موقتی؛ اگر سرویس‌دهنده فقط لینک بدهد، خودکار سراغ اتصال تصویر بعدی می‌رود.

### 🆕 جدید در نسخهٔ ۱.۸.۰ — سرور جدا برای تصویر + جایگزینی اولویت‌دار
- **کاربرد اتصال:** هر اتصال حالا «گفتگو و تصویر»، «فقط گفتگو» یا «فقط تصویر» است — تصویر شاخص می‌تواند از سروری کاملاً جدا از متن ساخته شود.
- **اولویت عددی و جایگزینی خودکار:** اتصال‌های هم‌کاربرد به‌ترتیب اولویت (عدد کوچک‌تر = اول) امتحان می‌شوند؛ هر اتصال بعد از ۳ خطای پیاپی کنار می‌رود و بعدی جایش را می‌گیرد.
- اتصال‌های قدیمی بدون هیچ تغییری کار می‌کنند و زنجیره‌های دستیِ صفحهٔ پرامپت‌ها همچنان مقدم‌اند.

### 🆕 جدید در نسخهٔ ۱.۷.۲ — اتصال آسان‌تر و پنل مرتب‌تر

- 🔌 **اتصال آسان به گیت‌وی‌ها و روترها (OmniRoute و…)**: اگر نشانی پایه /v1 نداشته باشد خودکار اضافه می‌شود، دکمهٔ «تست اتصال» در صورت شکست حالت /v1 را امتحان و فیلد را اصلاح می‌کند، و اگر نشانی به‌جای API یک صفحهٔ وب برگرداند، پیام خطا دقیقاً همین را می‌گوید.
- 🖧 **گزینهٔ «اجازه به نشانی‌های خصوصی/شبکهٔ محلی»** در تنظیمات ← پیشرفته: گیت‌وی سلف‌هاست روی دستگاه دیگری در شبکه، بدون نوشتن کد کار می‌کند (localhost همیشه مجاز بود).
- 🎨 **مرتب‌سازی ظاهر پنل**: سرصفحهٔ یکسان برای همهٔ صفحه‌ها (زمان‌بندی هم صاحب سرصفحه شد)، هم‌ترازی یکنواخت فیلدها، جدول‌های یکدست، انتخاب روزهای هفته به‌صورت دکمه‌های قرصی، و حذف همهٔ استایل‌های داخل‌خطی.

### 🆕 جدید در نسخهٔ ۱.۷.۰ — صف موضوع‌ها و فرمان دوطرفه از بله

- 📋 **صف موضوع‌ها**: انباری از موضوع‌ها که زمان‌بندی‌ها یکی‌یکی از آن می‌کشند — دستی پرش کنید یا با یک کلیک، پیشنهاد تازه از منابع خبری خودتان بگیرید (بدون تکرار؛ موضوع‌های استفاده‌شده هرگز دوباره پیشنهاد نمی‌شوند).
- 💬 **فرمان از داخل بله**: افزونه فقط اطلاع‌رسان نیست؛ از داخل بله بنویسید «نوشتن: موضوع X» تا پیش‌نویس در پس‌زمینه ساخته شود، «وضعیت»، «آخرین»، «انتشار» و «صف» هم کار می‌کنند — فقط برای گفتگوهای مجاز و حداکثر ۲۰ اجرا در روز.

### 🆕 جدید در نسخهٔ ۱.۶.۰ — اجرای پس‌زمینه، جدول کارها، امنیت شبکه و صندوق بازبینی

- 🚀 **اجرای پس‌زمینهٔ واقعی**: هر کار روی سرور با یک رویداد کرون خودادامه‌دار اجرا می‌شود — بستن تب مرورگر کار را متوقف نمی‌کند و کنسول فقط تماشا می‌کند (اندپوینت فقط-خواندنی `/state`).
- 🗄 **جدول اختصاصی کارها**: کارها از option به جدول `aipc_jobs` منتقل شدند (مهاجرت خودکار، ماندگاری قابل تنظیم ۹۰ روز پیش‌فرض، پرس‌وجوی سبک برای گزارش‌ها).
- 🛡 **گارد شبکهٔ خروجی (SSRF)**: نشانی‌های خصوصی/رزروشده مسدود می‌شوند؛ لوکال‌هاست برای Ollama و LM Studio باز است و با فیلترها قابل تنظیم است. به‌علاوه **محدودیت نرخ REST** برای هر کاربر در دقیقه.
- 📥 **صندوق بازبینی پیش‌نویس‌ها**: همهٔ پیش‌نویس‌های هوش مصنوعی در یک صفحه — ویرایش، پیش‌نمایش، انتشار یک‌کلیکی و بازنویسی مجدد.
- ❓ **راهنمای درون‌صفحه‌ای**: کنار هر بخش از صفحه‌های مدیریت یک آیکون «؟» است که با کلیک، توضیح کوتاه همان بخش را باز می‌کند.
- ✅ **CI با GitHub Actions**: لینت، بررسی ترجمه‌ها و کل مجموعهٔ تست e2e روی هر push و PR اجرا می‌شود.

### 🆕 جدید در نسخهٔ ۱.۵.۲ — اتصال گیت قابل تنظیم (مخزن، برنچ، توکن)

- ⚙️ **مخزن GitHub، برنچ و توکن دسترسی شخصی (PAT)** مستقیماً در صفحهٔ به‌روزرسانی ذخیره می‌شوند — توکن write-only است و در option جداگانهٔ غیر-autoload نگهداری می‌شود.
- 🔌 **دکمهٔ تست اتصال**: مخزن + برنچ + توکن را یکجا در برابر گیت‌هاب می‌سنجد و آخرین نسخه را گزارش می‌کند.
- 🔒 **پشتیبانی از مخازن خصوصی**: با توکن، بررسی نسخه احراز‌هویت‌شده انجام می‌شود و دانلود از endpoint رسمی zipball استفاده می‌کند.

### 🆕 جدید در نسخهٔ ۱.۵.۱ — به‌روزرسانی مستقیم از گیت

- ⬇️ **صفحهٔ «به‌روزرسانی از گیت»**: آخرین فایل‌های افزونه را مستقیم از مخزن گیت‌هاب دانلود و همین نصب را درجا جایگزین می‌کند — بررسی نسخه (با جلوگیری از تنزل نسخه)، پشتیبان خودکار از نسخهٔ قبلی، جایگزینی اتمی و **بازگردانی خودکار در صورت خطا**. شاخهٔ مخزن (main یا شاخهٔ توسعه) قابل انتخاب است.

### 🆕 جدید در نسخهٔ ۱.۵.۰ — بازنویسی، لینک‌سازی داخلی، انتشار خودکار، زنجیرهٔ جایگزین

- ♻️ **بازنویسی نوشته‌های قدیمی**: هر پست موجود را انتخاب کنید تا ایجنت آن را کاملاً بازنویسی کند — تحلیل نوشته، بازنویسی کامل متن (با حفظ واقعیت‌ها، لینک‌ها، وضعیت، نویسنده، نامک و دسته)، تازه‌سازی متادیتای سئو و تصویر شاخص جدید (اختیاری).
- 🔗 **لینک‌سازی داخلی خودکار**: هنگام نوشتن، ایجنت لینک نوشته‌های مرتبط موجود سایت را به‌طور طبیعی در متن می‌نوید (در مرحلهٔ برنامه‌ریزی انتخاب می‌شوند، حداکثر ۴ لینک).
- 🚀 **انتشار خودکار زمان‌بندی‌شده**: نوشته‌ها همچنان به‌صورت پیش‌فرض پیش‌نویس می‌مانند؛ اما هر اجرا می‌تواند بلافاصله منتشر کند یا بعد از تأخیر ۱۵ تا ۱۰۰۸۰ دقیقه.
- 🛡 **زنجیرهٔ اتصال‌های جایگزین**: برای هر مرحله چند اتصال هوش مصنوعی تعیین کنید؛ هر اتصال بودجهٔ تلاش مجدد خود را دارد و اگر یکی مدام شکست بخورد، ایجنت خودکار به بعدی سوئیچ می‌کند (برای مراحل متنی و تصویری).
- 👀 **آگاهی از نوشته‌های موجود**: مرحلهٔ انتخاب موضوع، عناوین نوشته‌های منتشرشدهٔ اخیر را می‌بیند و از تکرار موضوع مشابه پرهیز می‌کند.
- 📡 **سایت‌های مبدأ پژوهش**: تا ۸ نشانی تعریف کنید؛ ایجنت هنگام برنامه‌ریزی فید RSS آن‌ها را می‌خواند و موضوع و واقعیت‌ها را با آخرین مطالبشان هم‌راستا می‌کند.

### 🆕 جدید در نسخهٔ ۱.۴.۰ — چندگیرنده، گزارش دوره‌ای، سقف روزانه

- 👥 **گیرنده‌های متعدد بله**: هر تعداد شناسهٔ گفتگو (شخص یا @کانال) — هر خط یک شناسه؛ هر پست به همه ارسال می‌شود و نتیجهٔ تک‌تک آن‌ها در لاگ کار ثبت می‌شود.
- 🧾 **گزارش دوره‌ای در بله**: خلاصهٔ فعالیت روزانه یا هفتگی (کارها، موفق/ناموفق، پیش‌نویس‌ها، توکن‌ها، مصرف به تفکیک اتصال) در ساعت/روز دلخواه به همهٔ گیرنده‌ها.
- 🛑 **سقف پست‌های زمان‌بندی‌شده در روز**: حداکثر N پست خودکار در روز (۰ = بی‌نهایت)؛ شمارش امروز همیشه در صفحه نمایش داده می‌شود.

### 🆕 جدید در نسخهٔ ۱.۳.۰ — زمان‌بندی خودکار + اطلاع‌رسانی بله

- ⏰ **کران‌جاب زمان‌بندی‌شده**: هر تعداد زمان‌بندی با ساعت، روزهای هفته، موضوع (ثابت یا خودکار از پرامپت سایت) و گزینه‌های اجرا (لحن/طول/زبان/تصویر/FAQ/فهرست مطالب) تعریف کنید؛ ایجنت خودش در آن ساعت‌ها پست کامل می‌سازد. دکمهٔ «همین حالا اجرا کن» هم هر زمان‌بندی را فوری اجرا می‌کند و کنسول ایجنت آن را زنده نشان می‌دهد.
- 📨 **ربات بله**: بعد از ساخته‌شدن هر پست (دستی یا زمان‌بندی‌شده) به‌طور خودکار به شخص/کانال دلخواه در بله پیام بفرستید شامل **تصویر شاخص + خلاصه + لینک مقاله**؛ توکن ربات (write-only)، شناسهٔ گفتگو با دکمهٔ «شناسایی خودکار»، پیام آزمایشی و گزارش ارسال/خطا در لاگ کار.

### 🆕 جدید در نسخهٔ ۱.۲.۰ — چند اتصال، چند هوش مصنوعی

- 🔌 **اتصال‌های نامحدود**: هر تعداد سرویس‌دهنده (مثلاً OpenAI برای متن + یک سرویس تصویر دیگر) با نشانی، کلید، مدل گفتگو و مدل تصویر، دما، سقف توکن و مهلتِ جداگانه تعریف کنید — یکی **پیش‌فرض** است.
- 🧩 **زنجیرهٔ اتصال به هر مرحله**: در صفحهٔ «پرامپت‌ها و مراحل» برای هر مرحله چند اتصال به‌ترتیب جایگزینی تعیین کنید — متن‌ها و تصویر شاخص هرکدام می‌توانند سرویس‌های متفاوتی داشته باشند و در صورت خطای مکرر، خودکار به اتصال بعدی سوئیچ می‌شود (پیش‌فرض همه: اتصال پیش‌فرض).
- ✏️ **پرامپت اختصاصی هر مرحله**: قالب پرامپت هر ۱۳ مرحله قابل ویرایش است؛ اگر دست نزنید در حالت «پیش‌فرض» می‌ماند و با به‌روزرسانی افزونه بهبود می‌یابد. جدول جای‌نگهدارها (placeholders) کنار هر مرحله است.
- 📊 **پنل گزارش‌های کامل**: خلاصهٔ کلی (کارها، موفق/ناموفق، توکن مصرفی)، **مصرف به تفکیک هر اتصال**، فهرست کارها با صفحه‌بندی و صفحهٔ جزئیات هر کار شامل زمان‌بندی مراحل، **تمام فراخوانی‌های API** (اتصال، مدل، توکن پرامپت/تولید، مدت، خطا) و بازپخش کامل کنسول.
- 🧹 مهاجرت خودکار: تنظیمات قدیمی (نشانی/کلید) هنگام به‌روزرسانی به یک اتصال پیش‌فرض تبدیل می‌شود.

ویژگی‌ها:

- ⏰ زمان‌بندی خودکار تولید پست + 📨 اطلاع‌رسانی بله (عکس + خلاصه + لینک) بعد از هر پست
- 🎯 **پرامپت سایت**: هویت و هدف سایت را یک بار تعریف کنید؛ ایجنت همیشه on-brand می‌نویسد
- 🗂 **انتخاب خودکار دسته‌بندی** از بین دسته‌بندی‌های موجود نوشته‌ها
- 💡 **موضوع خودکار**: فیلد موضوع اختیاری است — خالی بگذارید تا ایجنت خودش موضوع بسازد
- 🧠 پایپ‌لاین ۱۳ مرحله‌ای با لاگ زنده و نوار پیشرفت
- ✍️ **پاس کپی‌رایتینگ و سئو**: بازنویسی کل متن از نظر اقناع، سئو و اصالت (کپی‌رایت)
- 🔎 **خلاصه Rank Math**: عنوان و توضیحات متا + کلیدواژه کانونی (همچنین Yoast)، نامک انگلیسی، چکیده، برچسب‌ها
- ❓ بلوک پرسش‌های متداول + اسکیمای FAQPage (نتایج غنی گوگل)
- 📑 فهرست مطالب لینک‌شده
- 🖼 تصویر شاخص **از روی موضوع + خلاصه** با dall-e-3 / gpt-image-1
- ↻ **تلاش خودکار مجدد** هر مرحله (۳ بار) قبل از اعلام خطا + دکمه تلاش دوبارهٔ دستی
- 📄 نتیجه به‌صورت پیش‌فرض **پیش‌نویس** است تا بازبینی کنید؛ انتشار فوری/با تأخیر هم اختیاری است
- 🌍 ۱۲+ زبان محتوا (از جمله فارسی) + ترجمهٔ کامل فارسی رابط و سازگاری با RTL
- ⏱ هر مرحله در یک درخواست جدا → بدون مشکل timeout؛ 📊 شمارش توکن؛ 🧹 حذف داده‌ها در uninstall (اختیاری)

## English — Overview

AI Post Creator turns the WordPress admin into an AI content agent. Set a **site prompt** once (what your site is about), and the agent picks one of your **existing post categories**, invents a topic that fits, writes the article, runs a **copywriting + SEO revision pass**, builds the **Rank Math summary**, generates a **featured image from the topic + summary**, and saves everything as a **draft** — with every step visible live and auto-retried until it passes.

**New in 1.9.0 — Gemini-style image generation:** a per-connection **Image route** option adds a second generation path — *Chat completions* — for gateways that return pictures as base64 inside a chat response (OpenRouter, Antigravity …). *Automatic* tries the images endpoint first and falls back by itself.

**New in 1.8.1 — deterministic base64 images:** each connection has an **Image delivery** option; *Force base64* receives the image bytes inside the API response and decodes them locally — no temporary links involved, and link-only responses fail over to the next image connection.

**New in 1.8.0 — separate image servers + priority failover:**
- Every connection has a **Purpose** — *chat & images*, *chat only* or *images only* — so featured images can come from a completely different server than the text.
- Every connection has a **Priority** number: matching connections are tried lowest-number-first, and after 3 consecutive failures the run automatically switches to the next one. Explicit per-step chains (Prompts page) still win.

**New in 1.7.2 — easier connections + admin UI polish:**

* **Gateway-friendly connections** — a base URL without `/v1` gets it appended automatically, the connection test probes the `/v1` variant and corrects the field for you (OmniRoute, OpenRouter & co.), and "that address returned a web page, not an API" errors are finally said in plain words
* **Allow private/LAN addresses** — new toggle under Settings → Advanced for self-hosted gateways on another machine in your network (the SSRF guard stays on for everything else)
* **Cleaner admin panel** — consistent page headers, unified field alignment, restyled tables, day-of-week pills, no more inline styles

**New in 1.7.0 — topic queue + two-way Bale commands:**

**New in 1.6.0 — background execution, jobs table, network guard, review inbox:**

* **True background execution** — every job runs server-side on a self-rescheduling cron event; closing the browser tab never stops a run and the console became a read-only viewer (new `/aipc/v1/state` endpoint)
* **Dedicated jobs table** — jobs moved from an option to a real database table with automatic migration, configurable 90-day retention and light-row queries for the logs screen
* **Outbound network guard (SSRF) + REST rate limiting** — private/reserved addresses are blocked (loopback stays open for Ollama/LM Studio, tunable via filters) and the agent endpoints enforce per-user per-minute limits
* **Draft review inbox** — every AI draft in one page with edit / preview / one-click publish / rewrite-again actions
* **Contextual help** — a "?" icon beside every section expands into a short explanation of what it does (all admin pages, fully translated)
* **Topic queue** — a FIFO bank of topics schedules pull from; fill it by hand or pull fresh, deduplicated suggestions straight from your research sources
* **Two-way Bale commands** — drive the plugin from inside Bale: «نوشتن: a topic» starts a draft, «وضعیت» / «آخرین» / «انتشار» / «صف» report and publish — authorized chats only
* **CI with GitHub Actions** — lint, translation completeness and the full php-wasm e2e suite on every push and pull request

**New in 1.5.2 — configurable Git connection (repository, branch, token):**

* **GitHub repository, branch and Personal Access Token** are saved right on the Update from Git page — the token is write-only and stored in a separate non-autoloaded option
* **Connection test button** validates the repository, branch and token together against GitHub and reports the latest version
* **Private repositories**: with a token, version checks are authenticated and downloads use the official api.github.com zipball endpoint

**New in 1.5.1 — update straight from Git:**

* **"Update from Git" admin page** — downloads the latest plugin files from the GitHub repository and replaces this installation in place: version check with a downgrade guard (plus a force-reinstall option), automatic backup of the previous version, atomic swap with automatic rollback on failure, and a selectable repository branch (main or a development branch).

**New in 1.5.0 — rewrite, internal linking, auto-publish, fallback chains, research sources:**

* **Rewrite existing posts** — pick any old post and give it a full copywriting + SEO refresh: analysis, a complete rewrite (keeping the facts, links, status, author, slug and category), refreshed SEO metadata and an optional new featured image
* **Automatic internal linking** — links to your existing related posts are woven into the content while writing (planned in the topic step, max 4)
* **Scheduled auto-publishing** — drafts stay the default, but any run can publish immediately or after a delay (15–10080 minutes)
* **Connection fallback chains** — several connections per step, each with its own retry budget; the agent switches to the next automatically (text and image steps)
* **Existing-post awareness** — the topic step sees your recent published titles and avoids duplicates
* **Research source sites** — up to 8 URLs; their RSS feeds ground the topic and facts during planning

**New in 1.4.0 — multiple recipients, periodic reports, daily limit:**

* **Multiple Bale recipients** — list any number of chat IDs (people or @channels, one per line); every generated post is delivered to all of them, with per-chat results logged.
* **Periodic Bale report** — a daily or weekly activity summary (jobs, success/fail, drafts, tokens, usage per connection) sent at your chosen time/day to every recipient.
* **Daily scheduled-post limit** — cap automatic posts at N per day (0 = unlimited); today's count is always visible on the Schedule page.

**New in 1.3.0 — automatic schedules + Bale notifications:**

* **Cron scheduling** — define any number of schedules (local time, weekdays, fixed or auto-invented topic, run options). The scheduler ticks every 15 minutes, fires due entries (one job per tick, same-day catch-up, no double firing), resumes interrupted automatic jobs and auto-retries failed ones up to 3 times. A **Run now** button starts any schedule immediately in the live agent console.
* **Bale messenger notifications** — after every generated post (manual or scheduled) the agent messages a Bale chat with the **featured image + summary + link**. Write-only bot token, a *Detect chat ID* helper, a test-message button, and send/failure lines in the job log.

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
| **AI Post Creator** (top level) | `edit_posts` | Agent console — options, live terminal (viewer for the background runner), step checklist, result card |
| **Rewrite post** | `edit_posts` | Rewrite an existing post with the agent |
| **Review drafts** | `edit_posts` | Every AI-generated draft in one inbox — edit, preview, publish, rewrite again |
| **Connections** | `manage_options` | Add/edit/delete connections, set the default, test + load models |
| **Prompts & Steps** | `manage_options` | Per-step connection assignment + editable prompt templates with placeholder docs |
| **Schedule** | `manage_options` | Automatic schedules (time/days/topic/options, run-now) + Bale notification settings + cron status |
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
| `/bale/test` | POST | `manage_options` | Send a Bale test message |
| `/bale/chat-id` | POST | `manage_options` | Detect the latest Bale chat id |
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

// React to every created post (e.g. custom notifications).
add_action( 'aipc_post_created', function ( $post_id, $job_id ) {
    // $post_id: the draft post, $job_id: the agent job.
}, 10, 2 );
```

## Security & privacy

* Capabilities: agents need `edit_posts`; connections/prompts/logs/settings need `manage_options`. Anonymous REST calls get 401.
* All AI output is sanitized: content with `wp_kses_post`, titles/slugs/terms with the matching `sanitize_*` functions.
* API keys never leave the server (server-to-provider only) and are never returned by any REST response or the connections list.
* The API key field is write-only in the UI (leave it empty to keep the stored key).
* **Outbound network guard (SSRF), 1.6+:** every outbound URL (connection base URLs, research source sites, provider-returned image URLs) is validated — private/reserved IP ranges are blocked, loopback stays allowed for local LLMs (Ollama, LM Studio); tunable via the `aipc_outbound_allowlist` / `aipc_allow_private_hosts` / `aipc_allow_loopback` filters.
* **REST rate limiting (1.6+):** the agent endpoints enforce per-user per-minute limits (HTTP 429); adjustable via the `aipc_rest_rate_limit` filter.
* **Two-way Bale commands (1.7+):** only the chat IDs configured in the plugin settings are obeyed; messages from any other chat are ignored silently, processed update ids are never re-run, and at most 20 posts per day can be started from Bale.
* See [SECURITY.md](SECURITY.md) for the full policy and design notes.
* Uninstall removes options and post meta only when explicitly enabled in settings.

## Quality — tested end to end

The plugin ships with a real-WordPress end-to-end suite (WordPress 6.7.1 on
SQLite via php-wasm, **two mock providers** — a chat host and a dedicated image
host — plus mock Bale/RSS/Git endpoints) that drives the agent through the
genuine REST stack:

- ✅ **46 result groups / 441 assertions green at v1.7.0, with zero PHP
  warnings** — job lifecycle, auto-invented topics, AI-chosen categories,
  Persian content, TOC anchors, copywriting/SEO pass, FAQ + JSON-LD, Rank Math
  summary, featured images, draft-only saving, per-step connection routing
  verified at the HTTP level, fallback chains, rewrite runs, schedules +
  daily limits, Bale traffic audits (per-chat), the fa_IR bundle incl. plural
  forms, and since 1.6: the network-guard matrix, the jobs table (migration,
  retention, legacy fallback), the background runner (a whole run server-side
  with zero `/step` calls), the `/state` endpoint, rate limiting, the review
  inbox and the Git self-updater end-to-end.
- The full coverage list lives in [`tests/e2e/README.md`](tests/e2e/README.md).
- **CI runs the same suite on every push and pull request**
  (`.github/workflows/ci.yml`): PHP 7.4-target lint, `node --check`, translation
  completeness and the e2e run — a red CI blocks the merge.

## Frequently asked questions

**Does it publish immediately?** — Never. The agent always saves a **draft** so you can review the AI content first.

**Can different steps use different providers?** — Yes. Create a connection per provider under *Connections*, then assign each step its connection under *Prompts & Steps* (unset steps use the default connection).

**Will long posts time out?** — No. Each step is a separate request; only a single section is generated per request.

**What if my provider has no image API?** — The image step is skipped gracefully with a log entry; the rest of the post is unaffected.

**Can I use a local model?** — Yes — Ollama and LM Studio work out of the box. Increase the connection's timeout for slower local models.

**I updated from 1.1 — where did my API settings go?** — They were migrated into a default connection under *AI Post Creator → Connections*. Nothing needs to be re-entered.

**How precise are the schedules?** — The scheduler ticks every 15 minutes; a run starts within ~15 minutes of its time (same-day catch-up, at most once per entry per day). For exact timing, disable WP-Cron and call `wp-cron.php` from a server cron job (the Schedule page shows the recommended line).

**What does the Bale message contain?** — The featured image (when the post has one), the title, the SEO summary and the link to the draft. If image sending fails, it falls back to a text-only message; every attempt is logged in the job's log.

**Can several people receive the notifications?** — Yes: list every chat ID on the Schedule page (one per line — a person's numeric ID or a @channel username). Each post and each periodic report is delivered to all of them, and partial failures are logged per chat.

**Persian/RTL support?** — The admin UI ships with a complete `fa_IR` translation and RTL-aware styling; the content language is switchable per run (Persian is auto-detected as default on Persian sites).

---

© 2026 Ahmad Naraghi — GPL v2 or later.
