#!/usr/bin/env python3
"""
Regenerate languages/wp-ai-post-creator.pot, .po and the compiled .mo (fa_IR).

Usage:
    python3 tests/e2e/make-translations.py

Override the plugin root with $AIPC_ROOT (default: this script's repo).

How it works
------------
1. Extracts every translatable string from the plugin PHP files
   (__, _e, esc_*__, _x, _ex and _n including plural forms).
2. Reads languages/wp-ai-post-creator-fa_IR.po for existing translations.
3. Any NEW string that is not translated yet aborts the run with a MISSING
   list — add those strings to NEW_TRANSLATIONS below (Persian) and re-run.
   Entries already present in the .po keep their translation; a NEW_TRANSLATIONS
   entry for an existing msgid REFRESHES its translation instead of duplicating.
4. Rewrites the .po (dropping strings that no longer exist), regenerates the
   .pot, and recompiles the binary .mo by hand (little-endian uint32 offset
   tables; plural originals are stored as "singular\x00plural").
"""
import re, glob, os, struct, sys

PLUGIN = os.environ.get('AIPC_ROOT') or os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DOMAIN = 'wp-ai-post-creator'

FUNCS = r"(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_js__|_x|_ex)"
CALL_S = re.compile(FUNCS + r"\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'wp-ai-post-creator'", re.S)
CALL_D = re.compile(FUNCS + r'\(\s*"((?:[^"\\]|\\.)*)"\s*,\s*\'wp-ai-post-creator\'', re.S)
CALL_N = re.compile(r"_n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,[^,]+,\s*'wp-ai-post-creator'", re.S)


def unesc(s):
    return s.replace("\\'", "'").replace('\\"', '"').replace('\\\\', '\\')


def poesc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"')


# Persian translations for strings added AFTER the initial 1.4.0 .po.
# key = English msgid; value = Persian msgstr, or a dict of plural forms
# {'0': singular-form, '1': plural-form} for _n() strings.
NEW_TRANSLATIONS = {
    'Allow private/LAN addresses (e.g. a self-hosted gateway like OmniRoute or Ollama on another machine in your network)': 'اجازه به نشانی\u200cهای خصوصی/شبکهٔ محلی (مثلاً گیت\u200cوی سلف\u200cهاست مانند OmniRoute یا Ollama روی دستگاه دیگری در شبکهٔ شما)',
    'Blocked: the host is a private or reserved address. Enable “Allow private/LAN addresses” under Settings → Advanced (or use the “aipc_outbound_allowlist” filter) to allow it.': 'مسدود شد: میزبان یک نشانی خصوصی یا رزروشده است. برای مجاز کردن آن، «اجازه به نشانی\u200cهای خصوصی/شبکهٔ محلی» را در تنظیمات ← پیشرفته فعال کنید (یا از فیلتر «aipc_outbound_allowlist» استفاده کنید).',
    'Connected via %s — the base URL was missing /v1, so the field was corrected. Save the connection to keep it.': 'اتصال از طریق %s برقرار شد — نشانی پایه /v1 نداشت و فیلد اصلاح شد. برای ماندگاری، اتصال را ذخیره کنید.',
    'Local & LAN providers': 'سرویس\u200cدهنده\u200cهای محلی و شبکهٔ داخلی',
    'Self-hosted gateways (OmniRoute, Ollama, LM Studio) run on your own machine — the WordPress server must be able to reach that address. If the gateway runs on another machine in your network, enter its LAN address and enable “Allow private/LAN addresses” under Settings → Advanced.': 'گیت\u200cوی\u200cهای سلف\u200cهاست (OmniRoute، Ollama، LM Studio) روی دستگاه خود شما اجرا می\u200cشوند — سرور وردپرس باید بتواند به آن نشانی دسترسی داشته باشد. اگر گیت\u200cوی روی دستگاه دیگری در شبکهٔ شماست، نشانی داخلی آن را وارد کنید و «اجازه به نشانی\u200cهای خصوصی/شبکهٔ محلی» را در تنظیمات ← پیشرفته فعال کنید.',
    'The address returned a web page (HTML) instead of an API response — it looks like a website URL, not an API base URL. OpenAI-compatible endpoints almost always end in /v1.': 'این نشانی به\u200cجای پاسخ API یک صفحهٔ وب (HTML) برگرداند — به نظر می\u200cرسد نشانی وب\u200cسایت است، نه نشانی پایهٔ API. اندپوینت\u200cهای سازگار با OpenAI تقریباً همیشه به /v1 ختم می\u200cشوند.',
    'The base URL must point to an OpenAI-compatible endpoint, usually ending in /v1 (e.g. https://api.openai.com/v1). A provider’s website address is not an API endpoint — if the test finds the API under /v1, the field is corrected automatically. The API key is write-only: leave the field empty to keep the stored key. Private or internal addresses are blocked by the outbound network guard unless you enable “Allow private/LAN addresses” in Settings → Advanced.': 'نشانی پایه باید به یک اندپوینت سازگار با OpenAI اشاره کند و معمولاً به /v1 ختم می\u200cشود (مثلاً https://api.openai.com/v1). نشانی وب\u200cسایتِ سرویس\u200cدهنده اندپوینت API نیست — اگر تست، API را زیر /v1 پیدا کند، فیلد به\u200cطور خودکار اصلاح می\u200cشود. کلید API فقط\u200cنوشتنی است: برای نگه داشتن کلید ذخیره\u200cشده، فیلد را خالی بگذارید. نشانی\u200cهای خصوصی یا داخلی توسط گارد شبکهٔ خروجی مسدود می\u200cشوند مگر «اجازه به نشانی\u200cهای خصوصی/شبکهٔ محلی» را در تنظیمات ← پیشرفته فعال کنید.',
    'localhost is always allowed. Only enable this when the AI gateway really runs inside your own network — it relaxes the outbound request guard (SSRF protection) for private addresses.': 'localhost همیشه مجاز است. این گزینه را فقط وقتی فعال کنید که گیت\u200cوی هوش مصنوعی واقعاً داخل شبکهٔ خودتان اجرا می\u200cشود — چون گارد درخواست\u200cهای خروجی (محافظت SSRF) را برای نشانی\u200cهای خصوصی سست می\u200cکند.',
    'the address returned a web page (HTML), not an API response — check that the base URL is the API endpoint (it usually ends in /v1)': 'این نشانی یک صفحهٔ وب (HTML) برگرداند، نه پاسخ API — بررسی کنید که نشانی پایه همان اندپوینت API باشد (معمولاً به /v1 ختم می\u200cشود)',
    '%d words · published': '%d کلمه · منتشر شد',
    '%d-step fallback chain': 'زنجیرهٔ جایگزین %d مرحله‌ای',
    '(No published posts yet.)': '(هنوز هیچ نوشتهٔ منتشرشده‌ای وجود ندارد.)',
    '15–10080 minutes (up to a week). Only used with “Publish later”.': '۱۵ تا ۱۰۰۸۰ دقیقه (حداکثر یک هفته). فقط با گزینهٔ «انتشار بعداً» استفاده می‌شود.',
    'A new AI post is published': 'یک نوشتهٔ جدید هوش مصنوعی منتشر شد',
    'AI connections chain (fallback order)': 'زنجیرهٔ اتصال‌های هوش مصنوعی (ترتیب جایگزینی)',
    'After creation': 'پس از ساخت',
    'Agent mode: give an existing post a full copywriting + SEO refresh — fresher copy, better structure, full originality.': 'حالت ایجنت: یک نوشتهٔ موجود را کاملاً بازنویسی و از نظر سئو و اصالت متن بهینه می‌کند — متن تازه‌تر، ساختار بهتر.',
    'An existing post was rewritten by the AI': 'یک نوشتهٔ موجود توسط هوش مصنوعی بازنویسی شد',
    'Analyzes the existing post (rewrite mode) and builds the improved outline.': 'نوشتهٔ موجود را تحلیل می‌کند (حالت بازنویسی) و طرح بهبودیافته می‌سازد.',
    'Attempt %1$d/%2$d on “%3$s” failed (%4$s) — retrying…': 'تلاش %1$d از %2$d روی «%3$s» ناموفق بود (%4$s) — تلاش دوباره…',
    'Bullet list of existing article titles (avoid duplicating them)': 'فهرست نشانگی از عناوین نوشته‌های موجود (برای پرهیز از تکرار)',
    'Bullet list of existing articles usable as internal links': 'فهرست نشانگی از نوشته‌های موجود که قابل استفاده به‌عنوان لینک داخلی هستند',
    'Bullet list of this site’s other articles usable as internal links': 'فهرست نشانگی از سایر نوشته‌های این سایت که قابل استفاده به‌عنوان لینک داخلی هستند',
    'Connection “%1$s” failed after %2$d attempts — switching to “%3$s”.': 'اتصال «%1$s» پس از %2$d تلاش ناموفق بود — تغییر به «%3$s».',
    'Create your first post with the agent, then come back to refresh it anytime.': 'نخستین نوشتهٔ خود را با ایجنت بسازید، بعد هر وقت خواستید برای تازه‌سازی برگردید.',
    'Delay (minutes)': 'تأخیر (دقیقه)',
    'Delay before publishing (minutes)': 'تأخیر پیش از انتشار (دقیقه)',
    'Hold Ctrl / Cmd to pick several. Each selected connection gets its own retry budget; when one keeps failing the agent automatically switches to the next. Empty = default connection.': 'با نگه‌داشتن Ctrl / Cmd چند مورد را انتخاب کنید. هر اتصال انتخاب‌شده بودجهٔ تلاش مجدد خود را دارد؛ اگر یکی مدام شکست بخورد، ایجنت خودکار به بعدی سوئیچ می‌کند. خالی = اتصال پیش‌فرض.',
    'Image generation failed on every connection — continuing without a featured image.': 'تولید تصویر در همهٔ اتصال‌ها ناموفق بود — ادامه بدون تصویر شاخص.',
    'Improved title from the analysis step': 'عنوان بهبودیافته از مرحلهٔ تحلیل',
    'Improved title: “%s”': 'عنوان بهبودیافته: «%s»',
    'Improvement notes from the analysis step': 'یادداشت‌های بهبود از مرحلهٔ تحلیل',
    'Internal linking list chosen in the plan step (or empty)': 'فهرست لینک‌دهی داخلی که در مرحلهٔ برنامه‌ریزی انتخاب شده (یا خالی)',
    'Internal linking: %d existing articles will be linked': 'لینک‌دهی داخلی: %d نوشتهٔ موجود لینک می‌شوند',
    'Internal links to weave in (or empty)': 'لینک‌های داخلی که باید در متن بیایند (یا خالی)',
    'New featured image': 'تصویر شاخص جدید',
    'No posts found.': 'هیچ نوشته‌ای یافت نشد.',
    'Optional: one URL per line (max 8). The agent reads each site’s RSS feed while planning and grounds the topic and facts in their latest articles.': 'اختیاری: هر خط یک نشانی (حداکثر ۸ مورد). ایجنت هنگام برنامه‌ریزی فید RSS هر سایت را می‌خواند و موضوع و واقعیت‌ها را با آخرین مطالبشان هم‌راستا می‌کند.',
    'Pick a post to rewrite first.': 'اول یک نوشته برای بازنویسی انتخاب کنید.',
    'Post published.': 'نوشته منتشر شد.',
    'Post published: #%d': 'نوشته منتشر شد: #%d',
    'Post to rewrite': 'نوشتهٔ موردنظر برای بازنویسی',
    'Post updated: #%d': 'نوشته به‌روزرسانی شد: #%d',
    'Posts are always created as drafts first; pick what happens next.': 'نوشته‌ها همیشه ابتدا به‌صورت پیش‌نویس ساخته می‌شوند؛ انتخاب کنید بعدش چه اتفاقی بیفتد.',
    'Publish': 'انتشار',
    'Reminder of the internal links that must survive the revision': 'یادآوری لینک‌های داخلی که باید در بازنویسی حفظ شوند',
    'Research items from the configured source sites (or empty)': 'مطالب پژوهشی از سایت‌های مبدأ پیکربندی‌شده (یا خالی)',
    'Research source sites': 'سایت‌های مبدأ پژوهش',
    'Rewrite another': 'بازنویسی نوشتهٔ دیگر',
    'Rewrite complete (%1$d → %2$d words)': 'بازنویسی کامل شد (%1$d → %2$d کلمه)',
    'Rewrite post': 'بازنویسی نوشته',
    'Rewrite: analysis & new outline': 'بازنویسی: تحلیل و طرح جدید',
    'Rewrite: full revision pass': 'بازنویسی: مرحلهٔ کامل بازبینی',
    'Rewrites the whole existing post: fresher copy, better SEO, full originality.': 'کل نوشتهٔ موجود را بازنویسی می‌کند: متن تازه‌تر، سئوی بهتر، اصالت کامل.',
    'Rewriting the existing post “%1$s” (#%2$d) — fresh copy, better SEO, full originality.': 'در حال بازنویسی نوشتهٔ موجود «%1$s» (#%2$d) — متن تازه، سئوی بهتر، اصالت کامل.',
    'Scheduled to publish at %s.': 'زمان‌بندی شد تا در %s منتشر شود.',
    'Section count from the analysis step': 'تعداد بخش‌ها از مرحلهٔ تحلیل',
    'The agent analyzes the post, rewrites every paragraph (keeping the facts and the links), refreshes the SEO metadata and can generate a new featured image.': 'ایجنت نوشته را تحلیل می‌کند، هر پاراگراف را بازنویسی می‌کند (با حفظ واقعیت‌ها و لینک‌ها)، متادیتای سئو را تازه می‌کند و می‌تواند تصویر شاخص جدیدی بسازد.',
    'The agent rewrites the post live — you can cancel anytime.': 'ایجنت نوشته را زنده بازنویسی می‌کند — هر لحظه می‌توانید لغو کنید.',
    'The current content of the post being rewritten': 'محتوای فعلی نوشته‌ای که بازنویسی می‌شود',
    'The image prompt step failed.': 'مرحلهٔ پرامپت تصویر ناموفق بود.',
    'The post is updated in place — its status, author, address and category stay untouched.': 'نوشته درجا به‌روزرسانی می‌شود — وضعیت، نویسنده، نشانی و دستهٔ آن دست‌نخورده می‌ماند.',
    'The post to rewrite was not found.': 'نوشته‌ای که باید بازنویسی شود پیدا نشد.',
    'The rewrite analysis did not include a title. Please retry.': 'تحلیل بازنویسی شامل عنوان نبود. دوباره تلاش کنید.',
    'The rewrite analysis returned no usable outline. Please retry.': 'تحلیل بازنویسی طرح قابل‌استفاده‌ای برنگرداند. دوباره تلاش کنید.',
    'The rewrite came back much shorter than the original. Retrying.': 'نتیجهٔ بازنویسی خیلی کوتاه‌تر از نسخهٔ اصلی شد. تلاش دوباره.',
    'The rewrite lost the article structure. Retrying.': 'بازنویسی ساختار مقاله را از دست داد. تلاش دوباره.',
    'The user suggests this topic: "%s" — build the article around it, adapted to the site context.': 'کاربر این موضوع را پیشنهاد داده: «%s» — مقاله را حول آن و متناسب با زمینهٔ سایت بساز.',
    'Title of the post being rewritten': 'عنوان نوشته‌ای که بازنویسی می‌شود',
    'Update post': 'به‌روزرسانی نوشته',
    'You are not allowed to edit this post.': 'شما اجازهٔ ویرایش این نوشته را ندارید.',
    'Your post was rewritten!': 'نوشتهٔ شما بازنویسی شد!',
    '— pick a post —': '— یک نوشته انتخاب کنید —',
    '⏱ +%d min': '⏱ +%d دقیقه',
    '⏱ Publish later': '⏱ انتشار بعداً',
    '📝 Draft': '📝 پیش‌نویس',
    '📝 Keep as draft': '📝 پیش‌نویس بماند',
    '🚀 Immediately': '🚀 بلافاصله',
    '🚀 Publish immediately': '🚀 انتشار بلافاصله',
    # v1.5.2 — Git connection settings (repo / branch / token)
    'Connection test succeeded — latest version: %s': 'تست اتصال موفق بود — آخرین نسخه: %s',
    'GitHub repository': 'مخزن گیت‌هاب',
    'GitHub returned HTTP %d while testing the connection.': 'گیت‌هاب هنگام تست اتصال، کد HTTP %d را برگرداند.',
    'Personal Access Token': 'توکن دسترسی شخصی',
    'Security check failed. Please go back and try again.': 'بررسی امنیتی ناموفق بود. لطفاً برگردید و دوباره تلاش کنید.',
    'The repository or branch was not found (HTTP 404). Check the owner/name and branch — or the token for private repositories.': 'مخزن یا شاخه پیدا نشد (HTTP 404). مالک/نام و شاخه را بررسی کنید — یا توکن را برای مخازن خصوصی.',
    'The test uses the values above; an empty token field tests the stored one.': 'تست با همین مقادیر بالا انجام می‌شود؛ اگر فیلد توکن خالی باشد، توکن ذخیره‌شده تست می‌شود.',
    'The token was rejected (HTTP %d).': 'توکن رد شد (HTTP %d).',
    'Update settings': 'تنظیمات به‌روزرسانی',
    'Update settings saved.': 'تنظیمات به‌روزرسانی ذخیره شد.',
    'Used for private repositories and higher rate limits. Stored on your site only, never displayed again, and sent only to github.com over HTTPS.': 'برای مخازن خصوصی و سقف درخواست بالاتر. فقط روی سایت خودتان ذخیره می‌شود، دوباره نمایش داده نمی‌شود و تنها از طریق HTTPS به github.com ارسال می‌شود.',
    'owner/name — for example ahmad75naraghi/wp-ai-post-creator': 'owner/name — برای نمونه ahmad75naraghi/wp-ai-post-creator',
    # v1.5.1 — Git self-updater
    'Available on GitHub': 'موجود در گیت‌هاب',
    'Check for updates now': 'همین حالا بررسی کن',
    'Could not create a temporary folder for the update.': 'ساخت پوشهٔ موقت برای به‌روزرسانی ممکن نشد.',
    'Could not create the backup folder.': 'ساخت پوشهٔ پشتیبان ممکن نشد.',
    'Could not extract the update package.': 'استخراج بستهٔ به‌روزرسانی ممکن نشد.',
    'Could not move the current plugin folder to the backup location.': 'انتقال پوشهٔ فعلی افزونه به محل پشتیبان ممکن نشد.',
    'Could not save the downloaded package.': 'ذخیرهٔ بستهٔ دانلودشده ممکن نشد.',
    'Download the latest plugin files straight from the GitHub repository and replace this installation in place.': 'آخرین فایل‌های افزونه را مستقیم از مخزن گیت‌هاب دانلود کنید و همین نصب را درجا جایگزین کنید.',
    'GitHub returned HTTP %d while checking the version.': 'گیت‌هاب هنگام بررسی نسخه، کد HTTP %d را برگرداند.',
    'Installed version': 'نسخهٔ نصب‌شده',
    'Last backup of the previous version: %s': 'آخرین پشتیبان نسخهٔ قبلی: %s',
    'Moving the new files into place failed — the previous version was restored.': 'جابه‌جایی فایل‌های جدید انجام نشد — نسخهٔ قبلی بازگردانده شد.',
    'Older than the installed version': 'قدیمی‌تر از نسخهٔ نصب‌شده',
    'Reinstall anyway (also when the branch is not newer)': 'به هر حال نصب مجدد کن (حتی وقتی شاخه جدیدتر نیست)',
    'Replace all plugin files with the latest version from GitHub?': 'همهٔ فایل‌های افزونه با آخرین نسخهٔ گیت‌هاب جایگزین شود؟',
    'Repository branch': 'شاخهٔ مخزن',
    'The branch “%1$s” holds version %2$s, which is not newer than the installed %3$s. Tick “Reinstall anyway” to force it.': 'شاخهٔ «%1$s» نسخهٔ %2$s را دارد که از نسخهٔ نصب‌شدهٔ %3$s جدیدتر نیست. برای اجبار، گزینهٔ «نصب مجدد به هر حال» را علامت بزنید.',
    'The branch “%s” holds an OLDER version than the installed one — updating would downgrade the plugin.': 'شاخهٔ «%s» نسخه‌ای قدیمی‌تر از نسخهٔ نصب‌شده دارد — به‌روزرسانی، افزونه را تنزل می‌دهد.',
    'The button downloads the repository snapshot, verifies the plugin header, backs up the current files to wp-content/aipc-backups and swaps the new files in. If anything fails, the previous version is restored automatically.': 'این دکمه تصویر لحظه‌ای مخزن را دانلود می‌کند، سرآیند افزونه را راستی‌آزمایی می‌کند، از فایل‌های فعلی در wp-content/aipc-backups پشتیبان می‌گیرد و فایل‌های جدید را جایگزین می‌کند. اگر چیزی شکست بخورد، نسخهٔ قبلی خودکار بازگردانده می‌شود.',
    'The download from GitHub failed (HTTP %d or invalid archive).': 'دانلود از گیت‌هاب ناموفق بود (HTTP %d یا بستهٔ نامعتبر).',
    'The downloaded package does not contain a valid plugin header.': 'بستهٔ دانلودشده سرآیند معتبر افزونه ندارد.',
    'The downloaded package is not a valid plugin archive.': 'بستهٔ دانلودشده یک بستهٔ افزونهٔ معتبر نیست.',
    'The installed plugin folder was not found.': 'پوشهٔ افزونهٔ نصب‌شده پیدا نشد.',
    'The plugins folder is not writable — update by FTP instead.': 'پوشهٔ افزونه‌ها قابل نوشتن نیست — با FTP به‌روزرسانی کنید.',
    'The remote version was refreshed.': 'نسخهٔ راه دور تازه‌سازی شد.',
    'The repository branch does not look like this plugin (no version header found).': 'شاخهٔ مخزن شبیه این افزونه نیست (سرآیند نسخه پیدا نشد).',
    'Up to date': 'به‌روز است',
    'Update available': 'به‌روزرسانی موجود است',
    'Update from Git': 'به‌روزرسانی از گیت',
    'Update now': 'همین حالا به‌روزرسانی کن',
    'Updated successfully to %s.': 'با موفقیت به %s به‌روزرسانی شد.',
    'Verification of the new files failed — the previous version was restored.': 'راستی‌آزمایی فایل‌های جدید ناموفق بود — نسخهٔ قبلی بازگردانده شد.',
    'Versions': 'نسخه‌ها',
    'Your settings, connections, schedules and posts are files-independent and stay untouched.': 'تنظیمات، اتصال‌ها، زمان‌بندی‌ها و نوشته‌های شما مستقل از فایل‌ها هستند و دست نمی‌خورند.',
    'main for stable releases, or a development branch.': 'main برای نسخه‌های پایدار، یا یک شاخهٔ توسعه.',
    'not checked yet': 'هنوز بررسی نشده',
    # v1.6 — background runner, jobs table, review inbox, network guard, rate limiting
    'AI-generated drafts waiting for your review. Publish, edit or rewrite them — nothing goes live until you say so.': 'پیش‌نویس‌های تولیدشده با هوش مصنوعی در انتظار بازبینی شما هستند. منتشر کنید، ویرایش کنید یا بازنویسی کنید — تا خودتان نگویید چیزی منتشر نمی‌شود.',
    'Blocked: loopback hosts are disabled on this site (aipc_allow_loopback filter).': 'مسدود: هاست‌های لوکال‌هاست در این سایت غیرفعال‌اند (فیلتر aipc_allow_loopback).',
    'Blocked: the host is a private or reserved address. Add it to the “aipc_outbound_allowlist” filter (or enable “aipc_allow_private_hosts”) to allow it.': 'مسدود: این هاست نشانی خصوصی یا رزروشده است. برای مجاز کردن، آن را به فیلتر «aipc_outbound_allowlist» اضافه کنید (یا «aipc_allow_private_hosts» را فعال کنید).',
    'Could not publish the draft. Please try again from the editor.': 'انتشار پیش‌نویس ممکن نشد. لطفاً از ویرایشگر دوباره تلاش کنید.',
    'Draft': 'پیش‌نویس',
    'Draft published.': 'پیش‌نویس منتشر شد.',
    'Drafts waiting for review': 'پیش‌نویس‌های در انتظار بازبینی',
    'Empty URL.': 'نشانی خالی است.',
    'Manual': 'دستی',
    'Modified': 'آخرین تغییر',
    'Nothing to review. The agent always saves new posts as drafts — they will appear here.': 'چیزی برای بازبینی نیست. ایجنت نوشته‌های جدید را همیشه به‌صورت پیش‌نویس ذخیره می‌کند — اینجا نمایش داده می‌شوند.',
    'Only http(s) URLs are allowed.': 'فقط نشانی‌های http(s) مجاز هستند.',
    'Origin': 'مبدأ',
    'Pending': 'در انتظار بررسی',
    'Preview': 'پیش‌نمایش',
    'Publish “%s” now?': '«%s» همین حالا منتشر شود؟',
    'Review drafts': 'بازبینی پیش‌نویس‌ها',
    'Rewrite': 'بازنویسی',
    'The URL has no host.': 'نشانی هاست ندارد.',
    'The agent runs on the server — you can close this tab, it keeps going.': 'ایجنت روی سرور اجرا می‌شود — می‌توانید این برگه را ببندید، کار ادامه پیدا می‌کند.',
    'The image URL was rejected by the outbound network guard.': 'نشانی تصویر توسط گارد شبکهٔ خروجی رد شد.',
    'The server-side runner seems stalled — progress has not changed for a while. Check that WP-Cron works on your site (see the Schedule page).': 'به نظر می‌رسد اجراکنندهٔ سمت سرور متوقف شده — مدتی است پیشرفتی دیده نمی‌شود. از کارکرد WP-Cron در سایت خود مطمئن شوید (صفحهٔ زمان‌بندی را ببینید).',
    'This base URL is blocked by the outbound network guard (private or reserved address).': 'این نشانی پایه توسط گارد شبکهٔ خروجی مسدود شده است (نشانی خصوصی یا رزروشده).',
    'Title': 'عنوان',
    'Too many requests — please wait a moment and try again.': 'درخواست‌های زیادی ارسال شده — لطفاً کمی صبر کنید و دوباره تلاش کنید.',
    'Words': 'کلمات',
    # v1.7 — topic queue + two-way Bale commands
    'A FIFO bank of topics for the schedules above: an entry with “Take the topic from the queue” consumes the oldest pending topic each time it fires, then the queue moves on — so every run writes about something new. Fill it by hand, or pull fresh ideas from your research sources with the suggest button.': 'انباری از موضوع‌ها برای زمان‌بندی‌های بالا: هر زمان‌بندی که «موضوع را از صف بردارد» هنگام اجرا، قدیمی‌ترین موضوع در انتظار را برمی‌دارد و صف جلو می‌رود — پس هر اجرا دربارهٔ چیزی تازه می‌نویسد. صف را دستی پر کنید یا با دکمهٔ پیشنهاد، ایده‌های تازه را از منابع پژوهشی بکشید.',
    'AI Post Creator — commands:': 'دستورهای ساخت نوشته با هوش مصنوعی:',
    'Accept commands from Bale chats': 'پذیرش فرمان از گفتگوهای بله',
    'Add selected to queue': 'افزودن انتخاب‌شده‌ها به صف',
    'Add to queue': 'افزودن به صف',
    'Add topics (one per line)': 'افزودن موضوع (یکی در هر خط)',
    'Added! Reloading…': 'اضافه شد! در حال بازخوانی…',
    'Adding…': 'در حال افزودن…',
    'Clear queue': 'خالی کردن صف',
    'Commands: نوشتن: <topic> · وضعیت · آخرین · انتشار · صف · راهنما': 'فرمان‌ها: نوشتن: <موضوع> · وضعیت · آخرین · انتشار · صف · راهنما',
    'Could not add the topics:': 'افزودن موضوع‌ها ممکن نشد:',
    'Could not fetch suggestions:': 'دریافت پیشنهادها ممکن نشد:',
    'Could not publish: %s': 'انتشار ممکن نشد: %s',
    'Daily limit reached: at most %d posts per day can be started from Bale. Try again tomorrow.': 'به سقف روزانه رسیده‌اید: حداکثر %d نوشته در روز را می‌توان از داخل بله شروع کرد. فردا دوباره تلاش کنید.',
    'Drafts waiting for review: %d': 'پیش‌نویس‌های در انتظار بازبینی: %d',
    'Every 5 minutes (AI Post Creator — Bale commands)': 'هر ۵ دقیقه (ساخت نوشته با هوش مصنوعی — فرمان‌های بله)',
    'Fetching suggestions from your sources…': 'در حال دریافت پیشنهاد از منابع شما…',
    'From sources': 'از منابع',
    'I did not understand that command. Send «راهنما» (or help) for the list of commands.': 'دستور را متوجه نشدم. برای فهرست فرمان‌ها «راهنما» یا help را بفرستید.',
    'No AI drafts yet — start one with «نوشتن: <topic>».': 'هنوز پیش‌نویسی از هوش مصنوعی نیست — با «نوشتن: <موضوع>» یکی شروع کنید.',
    'No draft found at that position — send «آخرین» to see the newest draft.': 'در آن موقعیت پیش‌نویسی پیدا نشد — «آخرین» را بفرستید تا جدیدترین پیش‌نویس را ببینید.',
    'No new topics found in your sources — try again later or add topics manually.': 'موضوع تازه‌ای در منابع شما پیدا نشد — بعداً دوباره تلاش کنید یا موضوع‌ها را دستی اضافه کنید.',
    'No research sources are configured. Add them on the Settings page first.': 'هیچ منبع پژوهشی‌ای تنظیم نشده است. اول آنها را در صفحهٔ تنظیمات اضافه کنید.',
    'Only chats listed in the plugin settings can use commands.': 'فقط گفتگوهایی که در تنظیمات افزونه ثبت شده‌اند می‌توانند فرمان بدهند.',
    'Pending topics are used one by one (oldest first). Nothing is wasted: used topics are remembered so they are never suggested again.': 'موضوع‌های در انتظار یکی‌یکی (از قدیمی‌ترین) استفاده می‌شوند. چیزی هدر نمی‌رود: موضوع‌های استفاده‌شده به خاطر سپرده می‌شوند تا دوباره پیشنهاد نشوند.',
    'Pending topics in the queue: %d': 'موضوع‌های در انتظار در صف: %d',
    'Pending topics: %d': 'موضوع‌های در انتظار: %d',
    'Post published via a Bale command.': 'پست با فرمان بله منتشر شد.',
    'Queue': 'صف',
    'Queue cleared.': 'صف خالی شد.',
    'Remove': 'حذف',
    'Remove every pending topic from the queue?': 'همهٔ موضوع‌های در انتظار از صف حذف شوند؟',
    'Runs: %1$d (⏳ %2$d · ✅ %3$d · ❌ %4$d)': 'اجراها: %1$d (⏳ %2$d · ✅ %3$d · ❌ %4$d)',
    'Status: draft — send «انتشار» to publish it.': 'وضعیت: پیش‌نویس — برای انتشارش «انتشار» را بفرستید.',
    'Status: published': 'وضعیت: منتشرشده',
    'Suggest topics from my sources': 'پیشنهاد موضوع از منابع من',
    'Take the topic from the queue': 'موضوع را از صف بردار',
    'The bot checks for new messages every ~5 minutes (via WP-Cron) and only obeys the chat IDs listed above. Anyone in those chats can: send «نوشتن: a topic» to start a draft, «وضعیت» for today’s runs, «آخرین» for the newest draft, «انتشار» to publish it, «صف» for the topic queue, and «راهنما» for the full list. Up to 20 posts per day can be started from Bale.': 'ربات تقریباً هر ۵ دقیقه (از طریق WP-Cron) پیام‌های تازه را بررسی می‌کند و فقط به شناسه‌های گفتگوی بالا گوش می‌دهد. هر کس در آن گفتگوها می‌تواند: با «نوشتن: یک موضوع» یک پیش‌نویس شروع کند، «وضعیت» برای اجراهای امروز، «آخرین» برای جدیدترین پیش‌نویس، «انتشار» برای انتشار آن، «صف» برای صف موضوع‌ها و «راهنما» برای فهرست کامل. حداکثر ۲۰ نوشته در روز را می‌توان از داخل بله شروع کرد.',
    'The queue is empty. Add topics below or suggest them from your research sources.': 'صف خالی است. موضوع‌ها را در پایین اضافه کنید یا از منابع پژوهشی پیشنهاد بگیرید.',
    'The topic is empty.': 'موضوع خالی است.',
    'The topic is too short — send e.g. «نوشتن: balcony gardening».': 'موضوع خیلی کوتاه است — مثلاً «نوشتن: باغبانی بالکن» را بفرستید.',
    'The topic queue is empty. Add topics on the Schedule page.': 'صف موضوع‌ها خالی است. در صفحهٔ زمان‌بندی موضوع اضافه کنید.',
    'The topic queue is full.': 'صف موضوع‌ها پر است.',
    'This topic is already in the queue: %s': 'این موضوع از قبل در صف است: %s',
    'Today’s status': 'وضعیت امروز',
    'Topic queue': 'صف موضوع‌ها',
    'Topic removed.': 'موضوع حذف شد.',
    'Topics added to the queue.': 'موضوع‌ها به صف اضافه شدند.',
    'Two-way commands': 'فرمان‌های دوسویه',
    'When this entry fires, it takes the oldest pending topic from the topic queue (box above). If the queue is empty it falls back to the fixed topic field — or to the site prompt when that is empty too.': 'هنگام اجرای این زمان‌بندی، قدیمی‌ترین موضوع در انتظار از صف موضوع‌ها (کادر بالا) برداشته می‌شود. اگر صف خالی باشد به فیلد موضوع ثابت برمی‌گردد — و اگر آن هم خالی باشد، از توضیح سایت موضوع ساخته می‌شود.',
    'e.g. Growing mushrooms at home&#10;A small balcony water garden': 'مثلاً پرورش قارچ در خانه&#10;باغچهٔ آبی کوچک در بالکن',
    '…and %d more': '…و %d مورد دیگر',
    '…and %d more in the queue.': '…و %d مورد دیگر در صف.',
    '✍️ Got it — I’m writing a draft about “%s” now. I’ll message you here as soon as it’s ready.': '✍️ دریافت شد — همین حالا پیش‌نویسی دربارهٔ «%s» می‌نویسم. به محض آماده‌شدن، همین‌جا خبر می‌دهم.',
    '✍️ نوشتن: <topic> — start a new draft about the topic': '✍️ نوشتن: <موضوع> — شروع پیش‌نویس تازه دربارهٔ موضوع',
    '❓ راهنما — this list': '❓ راهنما — همین فهرست',
    '📄 آخرین — the newest AI draft': '📄 آخرین — جدیدترین پیش‌نویس هوش مصنوعی',
    '📊 وضعیت — today’s runs, drafts and queue': '📊 وضعیت — اجراهای امروز، پیش‌نویس‌ها و صف',
    '📋 Pending topics (%d):': '📋 موضوع‌های در انتظار (%d):',
    '📋 صف — pending topics in the queue': '📋 صف — موضوع‌های در انتظار در صف',
    '🚀 Published: %s': '🚀 منتشر شد: %s',
    '🚀 انتشار [number] — publish the newest (or n-th) draft': '🚀 انتشار [شماره] — انتشار جدیدترین (یا نهایی) پیش‌نویس',
    # v1.6 — contextual help toggles ("?" icons)
    'A connection is one AI service: base URL, API key and the models to use. One connection is the default; steps without their own assignment use it. Use Test after saving to verify the setup.': 'هر اتصال یعنی یک سرویس هوش مصنوعی: نشانی پایه، کلید API و مدل‌های مورد استفاده. یکی از اتصال‌ها پیش‌فرض است؛ مراحلِ بدون اتصال اختصاصی از همان استفاده می‌کنند. بعد از ذخیره با «تست» درستی تنظیم را بررسی کنید.',
    'Aggregate statistics across all jobs: totals, success/fail counts, API calls and token usage per connection.': 'آمار تجمعی همهٔ کارها: جمع کل، شمار موفق/ناموفق، فراخوانی‌های API و مصرف توکن به تفکیک اتصال.',
    'Bale is an Iranian messenger. The bot token comes from @Bot_Father; each chat ID receives the post’s featured image, summary and link when a run finishes (and a periodic report, if enabled). Delivery results are recorded in the job log.': 'بله یک پیام‌رسان ایرانی است. توکن ربات را از @Bot_Father بگیرید؛ هر شناسهٔ گفتگو پس از پایان هر اجرا، تصویر شاخص، خلاصه و لینک نوشته را دریافت می‌کند (و در صورت فعال بودن، گزارش دوره‌ای). نتیجهٔ ارسال در لاگ کار ثبت می‌شود.',
    'Caps how many automatic posts may be created per calendar day (site timezone). 0 = unlimited. Today’s count is shown next to the field.': 'حداکثر تعداد نوشته‌های خودکاری که در هر روز تقویمی می‌توانند ساخته شوند (به وقت سایت). ۰ = نامحدود. شمار امروز کنار همین فیلد نمایش داده می‌شود.',
    'Compares the installed version with the remote branch. A newer remote version means an update is available.': 'نسخهٔ نصب‌شده را با برنچ راه دور مقایسه می‌کند. جدیدتر بودن نسخهٔ راه دور یعنی به‌روزرسانی موجود است.',
    'Default options for new runs: language, tone, length and the TOC/FAQ defaults. You can override them per run on the console page.': 'گزینه‌های پیش‌فرض اجراهای جدید: زبان، لحن، طول و پیش‌فرض‌های فهرست مطالب/سؤالات متداول. در هر اجرا می‌توانید آنها را در صفحهٔ کنسول تغییر دهید.',
    'Downloads the branch snapshot, verifies the plugin header, backs up the current files and swaps the new ones in — rolling back automatically if anything fails.': 'تصویر لحظه‌ای برنچ را دانلود می‌کند، سرآیند افزونه را راستی‌آزمایی می‌کند، از فایل‌های فعلی پشتیبان می‌گیرد و فایل‌های جدید را جایگزین می‌کند — اگر چیزی شکست بخورد، خودکار به نسخهٔ قبلی بازمی‌گردد.',
    'Each pipeline step can use its own connection, or an ordered fallback chain: if a provider keeps failing, the agent switches to the next one automatically. Empty = the default connection.': 'هر مرحلهٔ خط تولید می‌تواند اتصال خودش را داشته باشد یا یک زنجیرهٔ جایگزین مرتب: اگر یک سرویس مدام شکست بخورد، ایجنت خودکار به بعدی سوئیچ می‌کند. خالی = اتصال پیش‌فرض.',
    'Every AI-generated draft lands here until you decide: edit, preview, publish with one click, or send it back for another rewrite. Nothing goes live until you say so.': 'هر پیش‌نویسی که هوش مصنوعی می‌سازد اینجا می‌آید تا تصمیم بگیرید: ویرایش، پیش‌نمایش، انتشار با یک کلیک، یا فرستادن برای بازنویسی دوباره. تا خودتان نگویید چیزی منتشر نمی‌شود.',
    'Every HTTP request the agent made: step, connection, model, duration, tokens and any error — the ground truth when debugging provider problems.': 'هر درخواست HTTP که ایجنت فرستاده است: مرحله، اتصال، مدل، مدت زمان، توکن‌ها و خطای احتمالی — منبع حقیقت برای عیب‌یابی مشکلات سرویس.',
    'Every run with its status, progress, call count and tokens. Open a row for the full diary: every step, every API call and every log line. Jobs are kept for 90 days by default.': 'هر اجرا با وضعیت، پیشرفت، تعداد فراخوانی و توکن‌هایش. روی هر ردیف بروید تا دفترچهٔ کاملش را ببینید: همهٔ مراحل، همهٔ فراخوانی‌های API و همهٔ خطوط لاگ. کارها به‌طور پیش‌فرض ۹۰ روز نگه داشته می‌شوند.',
    'Extra system instructions appended to every step (brand rules, banned words…), and the uninstall data policy.': 'دستورالعمل‌های سیستمی اضافه که به همهٔ مراحل افزوده می‌شوند (قواعد برند، واژه‌های ممنوع…) و سیاست حذف داده‌ها هنگام حذف افزونه.',
    'Pick the post to rewrite. The agent rewrites it in place: status, author, address and category stay untouched — only the content (and optionally the image and SEO metadata) is refreshed.': 'نوشته‌ای را که باید بازنویسی شود انتخاب کنید. ایجنت آن را درجا بازنویسی می‌کند: وضعیت، نویسنده، نشانی و دسته دست نمی‌خورد — فقط محتوا (و در صورت فعال بودن، تصویر و متادیتای سئو) تازه می‌شود.',
    'Posts are always saved as drafts first. Here you choose what happens next: keep it as a draft (the safe default), publish immediately when the run finishes, or schedule publishing 15–10080 minutes later. Publishing needs the publish permission.': 'نوشته‌ها همیشه ابتدا به‌صورت پیش‌نویس ذخیره می‌شوند. اینجا انتخاب می‌کنید بعدش چه شود: پیش‌نویس بماند (پیش‌فرض امن)، بلافاصله پس از پایان اجرا منتشر شود، یا انتشار ۱۵ تا ۱۰۰۸۰ دقیقه بعدتر زمان‌بندی شود. انتشار نیازمند مجوز انتشار است.',
    'Prompts are the instructions sent to the AI for each step. A prompt left exactly at its default stays in “default” mode and is improved automatically on plugin updates; editing it freezes your own version.': 'پرامپت‌ها دستوراتی هستند که برای هر مرحله به هوش مصنوعی فرستاده می‌شوند. پرامپتی که دقیقاً روی مقدار پیش‌فرض بماند در حالت «پیش‌فرض» است و با به‌روزرسانی افزونه خودکار بهتر می‌شود؛ با ویرایش آن، نسخهٔ خودتان ثابت می‌شود.',
    'Ready-made endpoint patterns for popular providers — copy your provider’s pattern into the connection form above.': 'الگوهای آمادهٔ نشانی برای سرویس‌های پرکاربرد — الگوی سرویس خودتان را در فرم اتصال بالا کپی کنید.',
    'The base URL must point to an OpenAI-compatible endpoint, usually ending in /v1 (e.g. https://api.openai.com/v1). The API key is write-only: leave the field empty to keep the stored key. Private or internal addresses are blocked by the outbound network guard unless allowlisted.': 'نشانی پایه باید به یک نقطهٔ پایانی سازگار با OpenAI اشاره کند، معمولاً با /v1 در انتها (مثلاً https://api.openai.com/v1). کلید API فقط نوشتنی است: برای نگه‌داشتن کلید ذخیره‌شده فیلد را خالی بگذارید. نشانی‌های خصوصی/داخلی توسط گارد شبکهٔ خروجی مسدود می‌شوند مگر اینکه مجاز اعلام شوند.',
    'The console is a live viewer: the job runs on the server, so you can close this tab and it keeps going. Steps, logs and progress update automatically while the agent works — and if a step fails after all retries, the Retry button re-runs only that step.': 'کنسول یک نمایشگر زنده است: کار روی سرور اجرا می‌شود، پس می‌توانید این برگه را ببندید و کار ادامه پیدا می‌کند. مراحل، لاگ‌ها و پیشرفت هنگام کار ایجنت خودکار به‌روز می‌شوند — و اگر مرحله‌ای بعد از همهٔ تلاش‌ها شکست بخورد، دکمهٔ «تلاش دوباره» فقط همان مرحله را اجرا می‌کند.',
    'The global switch and size for AI featured images. The image model is set per connection; if image generation fails on every provider, the post is still saved — just without an image.': 'کلید سراسری و اندازهٔ تصاویر شاخص هوش مصنوعی. مدل تصویر برای هر اتصال جداگانه تنظیم می‌شود؛ اگر تولید تصویر در همهٔ سرویس‌ها شکست بخورد، نوشته بدون تصویر ذخیره می‌شود.',
    'The human-readable diary of the run, exactly as it was shown live in the console.': 'دفترچهٔ خوانای این اجرا، درست همان‌طور که زنده در کنسول دیده می‌شد.',
    'The job’s identity card: topic, mode, source (manual or scheduled), result and timing.': 'شناسنامهٔ کار: موضوع، حالت، مبدأ (دستی یا زمان‌بندی‌شده)، نتیجه و زمان‌بندی.',
    'The pipeline checklist of this run: done, skipped (e.g. the image), failed or pending.': 'چک‌لیست مراحل این اجرا: انجام‌شده، ردشده (مثلاً تصویر)، ناموفق یا در انتظار.',
    'The plugin checks every 15 minutes (via WP-Cron) and fires the earliest due entry: one full agent run per tick. Missed times are caught up on the next tick the same day, and an entry never fires twice on the same day.': 'افزونه هر ۱۵ دقیقه (از طریق WP-Cron) بررسی می‌کند و زودترین موردِ سررسیده را اجرا می‌کند: هر تیک، یک اجرای کامل ایجنت. زمان‌های از دست رفته در همان روز با تیک بعدی جبران می‌شوند و هر ورودی در یک روز فقط یک‌بار اجرا می‌شود.',
    'The site prompt tells the agent what this site is about: purpose, audience and voice. It drives topic invention, category choice and the writing style of every run — the single most important setting of the plugin.': 'پرامپت سایت به ایجنت می‌گوید این سایت دربارهٔ چیست: هدف، مخاطب و لحن. موضوع‌سازی، انتخاب دسته و سبک نوشتن هر اجرا از همین تنظیم می‌آید — مهم‌ترین تنظیم افزونه.',
    'Topic: leave it empty and the agent invents a fresh topic from the site prompt — it also checks your existing posts to avoid duplicates. Write your own idea and the whole article is built around it.': 'موضوع: خالی بگذارید تا ایجنت خودش موضوعی تازه از پرامپت سایت بسازد — نوشته‌های موجود را هم می‌بیند تا موضوع تکراری نسازد. ایدهٔ خودتان را بنویسید تا کل مقاله حول همان ساخته شود.',
    'WP-Cron only runs when the site receives visits. On low-traffic sites, disable it in wp-config.php and call wp-cron.php from a real system cron every minute — this panel shows whether the ticks are actually happening.': 'WP-Cron فقط وقتی اجرا می‌شود که سایت بازدید داشته باشد. در سایت‌های کم‌بازدید آن را در wp-config.php غیرفعال کنید و wp-cron.php را با یک کرون واقعی سیستمی هر دقیقه صدا بزنید — همین پنل نشان می‌دهد تیک‌ها واقعاً در حال اجرا هستند یا نه.',
    'What is this section for?': 'این بخش برای چیست؟',
    'Where updates come from: the GitHub repository, the branch to track, and an optional Personal Access Token for private repositories (write-only, stored on your site only).': 'منبع به‌روزرسانی‌ها: مخزن گیت‌هاب، برنچ مورد پیگیری و توکن دسترسی شخصی اختیاری برای مخازن خصوصی (فقط نوشتنی، تنها روی سایت خودتان ذخیره می‌شود).',
    # _n() plurals — key = singular msgid, value = {'0': fa singular form, '1': fa plural form}
    'Rewrite plan ready — %d section.': {'0': 'طرح بازنویسی آماده شد — %d بخش.', '1': 'طرح بازنویسی آماده شد — %d بخش.'},
    'Outline ready — %d section.': {'0': 'طرح نوشته آماده شد — %d بخش.', '1': 'طرح نوشته آماده شد — %d بخش.'},
    '%d draft waiting': {'0': '%d پیش‌نویس در انتظار بازبینی', '1': '%d پیش‌نویس در انتظار بازبینی'},
}

# ---------- collect ----------
occurrences = {}      # msgid -> [refs]
plurals = {}          # singular -> (plural, refs)

for path in sorted(glob.glob(PLUGIN + '/**/*.php', recursive=True)):
    rel = os.path.relpath(path, PLUGIN)
    if rel.startswith('languages') or rel.startswith('tests/'):
        continue
    src = open(path, encoding='utf-8').read()
    for m in CALL_S.finditer(src):
        occurrences.setdefault(unesc(m.group(1)), []).append(rel)
    for m in CALL_D.finditer(src):
        occurrences.setdefault(unesc(m.group(1)), []).append(rel)
    for m in CALL_N.finditer(src):
        sing, plur = unesc(m.group(1)), unesc(m.group(2))
        occurrences.setdefault(sing, []).append(rel)
        plurals[sing] = (plur, occurrences[sing])

# ---------- parse existing po ----------
po_path = PLUGIN + '/languages/' + DOMAIN + '-fa_IR.po'
po_src = open(po_path, encoding='utf-8').read()
header_end = po_src.index('\n\n') + 2
header = po_src[:header_end]

entries = []  # {refs, id, plural, str, strs}
for block in re.split(r'\n\n', po_src[header_end:]):
    block = block.strip()
    if not block:
        continue
    refs, msgid, msgid_pl, msgstrs = [], None, None, {}
    for line in block.split('\n'):
        if line.startswith('#:'):
            refs.append(line)
        elif line.startswith('msgid_plural '):
            msgid_pl = line[14:-1]
        elif line.startswith('msgid '):
            msgid = line[7:-1]
        elif line.startswith('msgstr['):
            msgstrs[line[8]] = line[11:-1]
        elif line.startswith('msgstr '):
            msgstrs['0'] = line[8:-1]
    if msgid is None:
        continue
    entries.append({
        'refs': refs, 'id': msgid, 'plural': msgid_pl,
        'str': msgstrs.get('0', ''),
        'strs': msgstrs if msgid_pl is not None else None,
    })


def po_unesc(s):
    return s.replace('\\"', '"').replace('\\\\', '\\')


# dedupe (a previous buggy run may have written duplicates)
seen_ids = set()
entries_deduped = []
for e in entries:
    mid = po_unesc(e['id'])
    if mid in seen_ids:
        continue
    seen_ids.add(mid)
    entries_deduped.append(e)
entries = entries_deduped

existing = {}
for e in entries:
    existing[po_unesc(e['id'])] = e

# ---------- missing check ----------
missing = []
for s in occurrences:
    if s not in existing and s not in NEW_TRANSLATIONS:
        missing.append(s)
if missing:
    print('MISSING TRANSLATIONS for %d new strings:' % len(missing))
    for s in sorted(missing):
        print('    %r: \'\',' % s)
    sys.exit(1)

# ---------- rebuild po ----------
out = [header.rstrip('\n')]
kept = 0
for e in entries:
    mid = po_unesc(e['id'])
    if mid not in occurrences and mid != '':
        continue  # dropped string
    kept += 1
    out.append('')
    out.append('#: ' + ' '.join(sorted(set(occurrences.get(mid, [])))))
    out.append('msgid "%s"' % poesc(mid))
    if e['plural'] is not None:
        out.append('msgid_plural "%s"' % poesc(po_unesc(e['plural'])))
        out.append('msgstr[0] "%s"' % poesc(po_unesc(e['strs'].get('0', ''))))
        out.append('msgstr[1] "%s"' % poesc(po_unesc(e['strs'].get('1', e['strs'].get('0', '')))))
    elif mid in NEW_TRANSLATIONS:
        # refresh the translation from the dict
        out.append('msgstr "%s"' % poesc(NEW_TRANSLATIONS[mid]))
    else:
        out.append('msgstr "%s"' % poesc(po_unesc(e['str'])))

new_count = 0
for s in sorted(NEW_TRANSLATIONS):
    if s not in occurrences:
        print('WARN: translated string no longer used:', s)
        continue
    if s in existing:
        continue  # already emitted (possibly refreshed) in the loop above
    new_count += 1
    val = NEW_TRANSLATIONS[s]
    out.append('')
    out.append('#: ' + ' '.join(sorted(set(occurrences[s]))))
    out.append('msgid "%s"' % poesc(s))
    if s in plurals:
        out.append('msgid_plural "%s"' % poesc(plurals[s][0]))
        forms = val if isinstance(val, dict) else {'0': val, '1': val}
        out.append('msgstr[0] "%s"' % poesc(forms['0']))
        out.append('msgstr[1] "%s"' % poesc(forms.get('1', forms['0'])))
    else:
        out.append('msgstr "%s"' % poesc(val if isinstance(val, str) else val['0']))
out.append('')
open(po_path, 'w', encoding='utf-8').write('\n'.join(out))

# ---------- pot ----------
pot = [header.rstrip('\n').replace('Language: fa', 'Language: en')]
for s in sorted(occurrences):
    pot.append('')
    pot.append('#: ' + ' '.join(sorted(set(occurrences[s]))))
    pot.append('msgid "%s"' % poesc(s))
    if s in plurals:
        pot.append('msgid_plural "%s"' % poesc(plurals[s][0]))
        pot.append('msgstr[0] ""')
        pot.append('msgstr[1] ""')
    else:
        pot.append('msgstr ""')
pot.append('')
open(PLUGIN + '/languages/' + DOMAIN + '.pot', 'w', encoding='utf-8').write('\n'.join(pot))

# ---------- mo ----------
def compile_mo(items):
    # items: list of (original, translation); '' header must be first.
    n = len(items)
    ids = b''.join(i[0].encode('utf-8') + b'\x00' for i in items)
    strs = b''.join(i[1].encode('utf-8') + b'\x00' for i in items)
    keystarts, valstarts = [], []
    oids = ostrs = 0
    for mid, mstr in items:
        keystarts.append(oids)
        valstarts.append(ostrs)
        oids += len(mid.encode('utf-8')) + 1
        ostrs += len(mstr.encode('utf-8')) + 1
    keystarts = [7 * 4 + 16 * n + o for o in keystarts]
    valstarts = [7 * 4 + 16 * n + len(ids) + o for o in valstarts]
    output = struct.pack('Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    for i, e in enumerate(items):
        output += struct.pack('ii', len(e[0].encode('utf-8')), keystarts[i])
    for i, e in enumerate(items):
        output += struct.pack('ii', len(e[1].encode('utf-8')), valstarts[i])
    return output + ids + strs

mo_items = [('', header.strip())]
for s in sorted(occurrences):
    if s in existing and s not in NEW_TRANSLATIONS:
        e = existing[s]
        if e['plural'] is not None:
            f0 = po_unesc(e['strs'].get('0', ''))
            f1 = po_unesc(e['strs'].get('1', f0))
            mo_items.append((s + '\x00' + po_unesc(e['plural']), f0 + '\x00' + f1))
        else:
            mo_items.append((s, po_unesc(e['str'])))
    elif s in NEW_TRANSLATIONS:
        val = NEW_TRANSLATIONS[s]
        if s in plurals:
            forms = val if isinstance(val, dict) else {'0': val, '1': val}
            mo_items.append((s + '\x00' + plurals[s][0], forms['0'] + '\x00' + forms.get('1', forms['0'])))
        else:
            mo_items.append((s, val if isinstance(val, str) else val['0']))
mo_items.sort(key=lambda e: e[0])
open(PLUGIN + '/languages/' + DOMAIN + '-fa_IR.mo', 'wb').write(compile_mo(mo_items))

print('po: %d kept + %d new = %d; mo compiled (%d entries); pot regenerated' % (
    kept - 1, new_count, kept + new_count - 1, len(mo_items) - 1))
