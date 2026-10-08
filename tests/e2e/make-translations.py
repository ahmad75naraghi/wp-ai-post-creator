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
    # --- v1.23.1: actionable transport-error hints -------------------
    "The server refused the connection. Usual causes: (1) the gateway only listens on 127.0.0.1 — start it bound to 0.0.0.0 so other machines can reach it; (2) the port is closed in the server firewall or cloud security group — open it for this site's IP; (3) this hosting blocks outbound connections on non-standard ports — ask the host or move the gateway behind port 443. If the gateway runs on the SAME server as WordPress, use http://127.0.0.1:PORT/v1 instead of the public IP.": 'سرور اتصال را رد کرد. علت\u200cهای رایج: (۱) گیت\u200cوی فقط روی 127.0.0.1 گوش می\u200cدهد — آن را روی 0.0.0.0 اجرا کنید تا از بیرون در دسترس باشد؛ (۲) پورت در فایروال سرور یا security group بسته است — آن را برای IP این سایت باز کنید؛ (۳) این هاست اتصال\u200cهای خروجی به پورت\u200cهای غیراستاندارد را می\u200cبندد — از پشتیبانی هاست بپرسید یا گیت\u200cوی را پشت پورت 443 ببرید. اگر گیت\u200cوی روی همان سرورِ وردپرس اجرا می\u200cشود، به\u200cجای IP عمومی از http://127.0.0.1:PORT/v1 استفاده کنید.',
    "The connection timed out — a firewall is probably dropping the packets silently (open the port for this site's IP), the IP is wrong, or the service is down.": 'اتصال به پایان زمان رسید — احتمالاً فایروال بسته\u200cها را بی\u200cصدا دور می\u200cریزد (پورت را برای IP این سایت باز کنید)، IP اشتباه است یا سرویس خاموش است.',
    'The hostname could not be resolved — check the address for typos, or use the server IP directly.': 'نام دامنه قابل تبدیل به IP نبود — نشانی را از نظر غلط تایپی بررسی کنید یا مستقیماً IP سرور را بگذارید.',
    'TLS/SSL problem — the certificate is invalid/self-signed or the port does not speak HTTPS at all. For a plain local gateway use http:// instead of https://.': 'مشکل TLS/SSL — گواهی نامعتبر یا خودامضاست، یا این پورت اصلاً HTTPS صحبت نمی\u200cکند. برای گیت\u200cوی محلی ساده از http:// به\u200cجای https:// استفاده کنید.',
    # --- v1.23.0: feed discovery + suggestions UX --------------------
    'Show more': 'بیشتر',
    'Never suggest this again': 'دیگر این را پیشنهاد نکن',
    'Dismissed — it will not be suggested again.': 'حذف شد — دیگر پیشنهاد نخواهد شد.',
    'Could not dismiss the suggestion:': 'حذف پیشنهاد ممکن نشد:',
    'no feed found': 'فیدی پیدا نشد',
    'Queue topics are a subject, not a fixed headline: they tell the agent what to write about, and it crafts its own sharper SEO title for the article.': 'موضوع\u200cهای صف «سوژه\u200cاند» نه تیتر قطعی: فقط می\u200cگویند مقاله درباره چه باشد و عامل خودش تیتر سئوشدهٔ بهتری برای آن می\u200cسازد.',
    'Write about this SUBJECT: "%s". It is only a theme from the ideas queue — NOT the final headline. Decide the best angle for this site\'s audience and craft your own sharper, search-friendly title; do not copy the subject text verbatim.': 'دربارهٔ این سوژه بنویس: «%s». این فقط یک موضوع از صف ایده\u200cهاست — نه تیتر نهایی. بهترین زاویه را برای مخاطب این سایت انتخاب کن و خودت تیتری تیزتر و جست\u200cوجوپسند بساز؛ متن سوژه را عیناً کپی نکن.',
    # --- v1.22.0: quality gate -------------------------------------
    'Very short article (%d words)': 'مقالهٔ خیلی کوتاه (%d واژه)',
    'Empty section heading': 'تیتر بخشِ خالی',
    'Duplicated section headings': 'تیترهای بخش تکراری',
    'Keyword stuffing: \u201c%s\u201d is repeated far too often': 'انباشت کلیدواژه: «%s» بیش از حد تکرار شده است',
    'The focus keyword \u201c%s\u201d never appears in the text': 'کلیدواژهٔ کانونی «%s» هیچ\u200cجا در متن نیامده است',
    '%1$d machine-clich\u00e9 phrase(s), e.g. \u201c%2$s\u201d': '%1$d عبارت کلیشه\u200cای ماشینی، مثلاً «%2$s»',
    '%d internal link(s) do not resolve to a post': '%d پیوند داخلی به هیچ نوشته\u200cای نمی\u200cرسد',
    'A post with the exact same title already exists (#%d)': 'نوشته\u200cای با همین عنوانِ دقیق از قبل وجود دارد (#%d)',
    'No featured image was generated': 'هیچ تصویر شاخصی ساخته نشد',
    'Quality gate: score %1$d/100 is below %2$d — auto-publish cancelled, the post stays a draft for review. Issues: %3$s': 'دروازهٔ کیفیت: امتیاز %1$d از ۱۰۰ کمتر از %2$d است — انتشار خودکار لغو شد و نوشته برای بازبینی پیش\u200cنویس می\u200cماند. ایرادها: %3$s',
    'Quality score: %d/100.': 'امتیاز کیفیت: %d از ۱۰۰.',
    'Quality gate: score %1$d/100 — auto-publish was cancelled, the post stays a draft. Issues: %2$s': 'دروازهٔ کیفیت: امتیاز %1$d از ۱۰۰ — انتشار خودکار لغو شد و نوشته پیش\u200cنویس می\u200cماند. ایرادها: %2$s',
    'quality-gated drafts': 'پیش\u200cنویسِ دروازهٔ کیفیت',
    'Quality gate kept %d post(s) as draft': 'دروازهٔ کیفیت %d نوشته را پیش\u200cنویس نگه داشت',
    # --- v1.22.0: image repair ---------------------------------------
    'Featured images are disabled in the settings.': 'تصویر شاخص در تنظیمات غیرفعال است.',
    'The post to repair was not found.': 'نوشته\u200cای که باید ترمیم شود پیدا نشد.',
    'Attach image': 'پیوست عکس',
    'Featured image repaired for \u201c%1$s\u201d (#%2$d).': 'تصویر شاخص «%1$s» ترمیم شد (#%2$d).',
    'No image could be generated — the post keeps no featured image for now.': 'هیچ عکسی ساخته نشد — نوشته فعلاً بدون تصویر شاخص می\u200cماند.',
    'Repair missing images': 'ترمیم عکس\u200cهای جاافتاده',
    'Scans your posts for missing featured images and starts a quiet image-only job per post (max 10 per scan): the content is never touched, only the image is generated and attached — with the same retry logic as force-image mode.': 'نوشته\u200cها را برای تصویر شاخصِ جاافتاده می\u200cکاود و برای هر نوشته یک کارِ بی\u200cسروصدای فقط\u200cعکس می\u200cسازد (حداکثر ۱۰ در هر پویش): به محتوا دست نمی\u200cخورد، فقط عکس ساخته و پیوست می\u200cشود — با همان منطق تلاش دوبارهٔ حالت عکس اجباری.',
    '%d image-repair job(s) started — the images are generated in the background and attached as soon as they are ready.': '%d کار ترمیم عکس شروع شد — عکس\u200cها در پس\u200cزمینه ساخته و به\u200cمحض آماده\u200cشدن پیوست می\u200cشوند.',
    'Nothing to repair — every post already has a featured image (or a repair was started within the last hour).': 'چیزی برای ترمیم نیست — همهٔ نوشته\u200cها تصویر شاخص دارند (یا در یک ساعت گذشته ترمیمی شروع شده است).',
    'rescue images': 'تصاویر نجات',
    'Rescue images used (stock/default): %d': 'تصاویر نجات استفاده\u200cشده (استوک/پیش\u200cفرض): %d',
    # --- v1.22.0: connection health ----------------------------------
    'On cooldown after repeated failures — temporarily moved to the end of every chain.': 'به\u200cدلیل خطاهای پیاپی در حالت استراحت است — موقتاً به انتهای همهٔ زنجیره\u200cها منتقل شده.',
    'Cooling down': 'در حال استراحت',
    'Some calls failed today.': 'بعضی تماس\u200cهای امروز ناموفق بودند.',
    'Unstable today': 'امروز ناپایدار',
    '%1$s — %2$d failed calls today': '%1$s — %2$d تماس ناموفق امروز',
    # --- v1.22.0: content refresh ------------------------------------
    'No post is old enough to need refreshing right now.': 'فعلاً هیچ نوشته\u200cای آن\u200cقدر قدیمی نیست که به تازه\u200cسازی نیاز داشته باشد.',
    'Entry type': 'نوع ردیف',
    'Write a new post': 'نوشتن پست جدید',
    'Refresh an old post (auto-pick)': 'تازه\u200cسازی یک پست قدیمی (انتخاب خودکار)',
    'Refresh: the oldest published post not touched for 90+ days is rewritten — same title and URL, fresh copy and SEO. The topic/queue fields are ignored.': 'تازه\u200cسازی: قدیمی\u200cترین پست منتشرشده\u200cای که بیش از ۹۰ روز دست\u200cنخورده مانده بازنویسی می\u200cشود — با همان عنوان و نشانی، با متن و سئوی تازه. فیلدهای موضوع/صف نادیده گرفته می\u200cشوند.',
    'Refresh old posts': 'تازه\u200cسازی پست\u200cهای قدیمی',
    'Force image generation': 'تولید اجباری عکس',
    'Never finish a post without a featured image (default for all new runs)': 'هیچ پستی بدون تصویر شاخص تمام نشود (پیش\u200cفرض همهٔ اجراهای جدید)',
    'The post is only saved, published and announced on Bale after a featured image really exists. When generation fails, the agent retries every image connection at growing intervals (1 minute up to 1 hour) for up to 24 hours; only then are the stock/default rescue images used as the final resort. Each manual run and schedule entry can override this.': 'پست فقط زمانی ذخیره، منتشر و در بله اعلام می\u200cشود که تصویر شاخص واقعاً وجود داشته باشد. اگر تولید شکست بخورد، عامل تا ۲۴ ساعت همهٔ اتصال\u200cهای تصویر را با فاصله\u200cهای افزایشی (از ۱ دقیقه تا ۱ ساعت) دوباره امتحان می\u200cکند؛ تنها پس از آن تصاویر نجات (استوک/پیش\u200cفرض) به\u200cعنوان آخرین راه استفاده می\u200cشوند. هر اجرای دستی و هر ردیف زمان\u200cبندی می\u200cتواند این پیش\u200cفرض را تغییر دهد.',
    'retry until the image exists — never finish without it': 'تا ساخته\u200cشدن عکس تلاش می\u200cکند — هرگز بدون عکس تمام نمی\u200cشود',
    'Image retry window exhausted — using the rescue images (stock/default) as the last resort.': 'بازهٔ تلاش برای عکس به پایان رسید — تصاویر نجات (استوک/پیش\u200cفرض) به\u200cعنوان آخرین راه استفاده می\u200cشوند.',
    'A featured image is required (force mode) — round %1$d failed on every connection (%3$s). Next attempt in %2$s; the post will only be finished once the image exists.': 'تصویر شاخص الزامی است (حالت اجباری) — دور %1$d روی همهٔ اتصال\u200cها ناموفق بود (%3$s). تلاش بعدی تا %2$s دیگر؛ پست فقط پس از ساخته\u200cشدن عکس تمام می\u200cشود.',
    'Search-intent token from the plan step': 'نشانهٔ هدف جست\u200cوجو از مرحلهٔ برنامه\u200cریزی',
    'Recommended structure from the plan step': 'ساختار پیشنهادی از مرحلهٔ برنامه\u200cریزی',
    'Concrete element planned for this section in the outline (or a generic fallback)': 'عنصر مشخصی که در طرح کلی برای این بخش برنامه\u200cریزی شده (یا جایگزین عمومی)',
    'Duplicate request blocked: a job is already writing “%s” right now. Wait for it to finish, or enable “Allow duplicate topic” to override.': 'درخواست تکراری مسدود شد: همین حالا یک کار در حال نوشتن «%s» است. صبر کنید تمام شود، یا «اجازهٔ موضوع تکراری» را فعال کنید.',
    'Duplicate request blocked: “%s” is already waiting in the topic queue and will be written on schedule.': 'درخواست تکراری مسدود شد: «%s» از قبل در صف موضوع\\u200cها منتظر است و طبق زمان\\u200cبندی نوشته خواهد شد.',
    'Duplicate request blocked: an article about this topic was already created recently — “%1$s” (post #%2$d). Change the topic, or enable “Allow duplicate topic” to write it again.': 'درخواست تکراری مسدود شد: به\\u200cتازگی مقاله\\u200cای دربارهٔ همین موضوع ساخته شده — «%1$s» (نوشتهٔ #%2$d). موضوع را عوض کنید، یا برای نوشتن دوباره «اجازهٔ موضوع تکراری» را فعال کنید.',
    'An identical request arrived moments ago and is already being processed — duplicate blocked.': 'درخواستی یکسان لحظاتی پیش رسیده و در حال پردازش است — درخواست تکراری مسدود شد.',
    'This job already created post #%d — duplicate finalize skipped.': 'این کار قبلاً نوشتهٔ #%d را ساخته است — ذخیرهٔ تکراری نادیده گرفته شد.',
    'Allow duplicate topic': 'اجازهٔ موضوع تکراری',
    'skip the repeated-topic guard this once': 'فقط همین یک بار از سد موضوع تکراری عبور کن',
    'AI image generation failed — attached a CC-licensed stock photo from Openverse instead (#%d, attribution saved on the attachment).': 'ساخت تصویر با هوش مصنوعی شکست خورد — به\u200cجایش یک عکس استوک با مجوز CC از Openverse پیوست شد (#%d، منبع روی خود پیوست ذخیره شد).',
    'AI image generation failed — using the default featured image (#%d).': 'ساخت تصویر با هوش مصنوعی شکست خورد — از تصویر شاخص پیش\u200cفرض استفاده شد (#%d).',
    'Could not download any of the stock photo candidates.': 'هیچ\u200cکدام از گزینه\u200cهای عکس استوک دانلود نشد.',
    'Default featured image': 'تصویر شاخص پیش\u200cفرض',
    'Empty stock photo query.': 'عبارت جست\u200cوجوی عکس استوک خالی است.',
    'If every AI image connection fails, fetch a free CC-licensed photo from Openverse that matches the article subject': 'اگر همهٔ اتصال\u200cهای تصویرِ هوش مصنوعی شکست خوردند، یک عکس رایگان با مجوز CC از Openverse متناسب با موضوع مقاله گرفته شود',
    'Openverse (a WordPress project) needs no API key. The photo credit is saved in the attachment caption, as Creative Commons licenses require.': 'Openverse (از پروژه\u200cهای وردپرس) به کلید API نیاز ندارد. اعتبار عکاس — طبق الزام مجوزهای کریتیو کامنز — در توضیح پیوست ذخیره می\u200cشود.',
    'Openverse returned no results (HTTP %d).': 'Openverse نتیجه\u200cای برنگرداند (HTTP %d).',
    'Openverse returned no usable photo candidates.': 'Openverse هیچ گزینهٔ عکس قابل استفاده\u200cای برنگرداند.',
    'Photo: %1$s — via Openverse (%2$s)': 'عکس: %1$s — از Openverse (%2$s)',
    'Stock photo download failed (HTTP %d).': 'دانلود عکس استوک شکست خورد (HTTP %d).',
    'Stock photo fallback': 'عکس استوک جایگزین',
    'Stock photo fallback failed: %s': 'جایگزین عکس استوک شکست خورد: %s',
    'The default featured image setting could not be resolved to an image.': 'مقدار «تصویر شاخص پیش\u200cفرض» به یک تصویر معتبر نرسید.',
    'The global switch and size for AI featured images. The image model is set per connection. When generation fails on every provider, the rescue ladder kicks in: a rejected model id is swapped for one the gateway actually offers, then the Openverse stock fallback (if enabled), then the default featured image — so posts need never go out without a picture.': 'کلید کلی و اندازهٔ تصاویر شاخص هوش مصنوعی. مدل تصویر برای هر اتصال جداگانه تنظیم می\u200cشود. وقتی ساخت تصویر روی همهٔ سرویس\u200cدهنده\u200cها شکست بخورد، نردبان نجات وارد می\u200cشود: مدلِ ردشده با مدلی که دروازه واقعاً دارد عوض می\u200cشود، بعد عکس استوک Openverse (اگر فعال باشد)، بعد تصویر شاخص پیش\u200cفرض — تا هیچ پستی بدون عکس نماند.',
    'The guaranteed last resort: used when AI generation and the stock fallback both fail, so every post still gets a featured image. Paste a media-library image ID or URL (an external URL is imported once and reused). Leave empty to allow posts without an image.': 'آخرین راه تضمینی: وقتی هم ساخت با هوش مصنوعی و هم عکس استوک شکست بخورند استفاده می\u200cشود تا هر پست باز هم تصویر شاخص داشته باشد. شناسهٔ (ID) یا آدرس تصویری از کتابخانهٔ رسانه را بچسبانید (آدرس خارجی یک\u200cبار وارد و بعد بازاستفاده می\u200cشود). خالی بگذارید تا پست بدون عکس مجاز باشد.',
    'Unknown author': 'پدیدآور ناشناس',
    'Unsafe stock photo URL blocked.': 'آدرس ناامن عکس استوک مسدود شد.',
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
