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
    # _n() plurals — key = singular msgid, value = {'0': fa singular form, '1': fa plural form}
    'Rewrite plan ready — %d section.': {'0': 'طرح بازنویسی آماده شد — %d بخش.', '1': 'طرح بازنویسی آماده شد — %d بخش.'},
    'Outline ready — %d section.': {'0': 'طرح نوشته آماده شد — %d بخش.', '1': 'طرح نوشته آماده شد — %d بخش.'},
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
