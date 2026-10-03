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
