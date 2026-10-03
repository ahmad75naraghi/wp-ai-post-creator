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
    '%s ago': '%s پیش',
    '(no topic)': '(بدون موضوع)',
    'Agent runner — executes the next step of a job': 'اجراکنندهٔ ایجنت — گام بعدی یک کار را اجرا می\u200cکند',
    'Agent runs that are still running, queued, or stopped on an error. Cancelling marks the job as cancelled and removes its runner cron event, so it will never resume on its own.': 'اجراهای ایجنت که هنوز در حال اجرا، در صف یا روی خطا متوقف\u200cاند. لغو کردن، کار را «لغوشده» علامت می\u200cزند و رویداد کرونِ اجراکننده\u200cاش را حذف می\u200cکند تا دیگر هرگز خودبه\u200cخود ادامه پیدا نکند.',
    'All unfinished jobs cancelled.': 'همهٔ کارهای ناتمام لغو شدند.',
    'Back to draft': 'بازگشت به پیش\u200cنویس',
    'Bot poll — fetches new bot messages (fallback when the webhook is off)': 'سرکشی ربات — پیام\u200cهای جدید ربات را می\u200cگیرد (جایگزین وقتی وب\u200cهوک خاموش است)',
    'Cancel all': 'لغو همه',
    'Cancel every unfinished job?': 'همهٔ کارهای ناتمام لغو شوند؟',
    'Cancel publish': 'لغو انتشار',
    'Delayed publish': 'انتشار با تأخیر',
    'Delayed publish cancelled — the post stays a draft.': 'انتشار با تأخیر لغو شد — نوشته پیش\u200cنویس می\u200cماند.',
    'Everything still running or waiting in the background — stop any of it with one click so nothing piles up.': 'هرچه هنوز در پس\u200cزمینه در حال اجرا یا در انتظار است — هرکدام را با یک کلیک متوقف کنید تا چیزی روی هم تلنبار نشود.',
    'Hook': 'هوک',
    'In two days 09:00': 'پس\u200cفردا ۰۹:۰۰',
    'Job cancelled.': 'کار لغو شد.',
    'Jobs & Cron': 'کارها و کرون',
    'Last activity': 'آخرین فعالیت',
    'Next run': 'اجرای بعدی',
    'No AI draft is waiting to be scheduled — start one with «نوشتن: topic» first.': 'هیچ پیش\u200cنویس هوش مصنوعی منتظر زمان\u200cبندی نیست — اول با «نوشتن: موضوع» یکی بسازید.',
    'No pending publishes.': 'انتشار در انتظاری نیست.',
    'No plugin cron events are registered right now.': 'فعلاً هیچ رویداد کرونی از افزونه ثبت نشده است.',
    'Nothing is running — all jobs are finished.': 'چیزی در حال اجرا نیست — همهٔ کارها تمام شده\u200cاند.',
    'Pending publishes': 'انتشارهای در انتظار',
    'Post': 'نوشته',
    'Posts waiting to go live: delayed auto-publishes created by the agent, and posts you scheduled (from the bot or the editor). Cancelling keeps the post as a draft — nothing is deleted.': 'نوشته\u200cهایی که منتظر انتشارند: انتشارهای خودکارِ با تأخیر که ایجنت ساخته، و نوشته\u200cهایی که خودتان (از ربات یا ویرایشگر) زمان\u200cبندی کرده\u200cاید. لغو کردن، نوشته را به\u200cصورت پیش\u200cنویس نگه می\u200cدارد — چیزی حذف نمی\u200cشود.',
    'Progress': 'پیشرفت',
    'Publishes at': 'زمان انتشار',
    'Queued': 'در صف',
    'Recurring plugin events': 'رویدادهای تکرارشوندهٔ افزونه',
    'Repeats': 'تکرار',
    'Schedule tick — checks whether a scheduled topic is due': 'تیک زمان\u200cبندی — بررسی می\u200cکند موعد موضوع زمان\u200cبندی\u200cشده\u200cای رسیده یا نه',
    'Scheduled post': 'نوشتهٔ زمان\u200cبندی\u200cشده',
    'Scheduled post moved back to draft.': 'نوشتهٔ زمان\u200cبندی\u200cشده به پیش\u200cنویس برگشت.',
    'Source': 'منبع',
    'Tap a quick time below, or send the date and time in one message — Jalali «1404/07/20 18:30» or Gregorian «2026-10-12 18:30»; «فردا 18:30» and «امروز 22:00» work too. Tip: next time just reply to the draft notification with a date — no button needed. Send «لغو» to cancel.': 'یکی از زمان\u200cهای سریع پایین را بزنید، یا تاریخ و ساعت را در یک پیام بفرستید — شمسی «1404/07/20 18:30» یا میلادی «2026-10-12 18:30»؛ «فردا 18:30» و «امروز 22:00» هم کار می\u200cکنند. نکته: دفعهٔ بعد کافی است به پیامِ پیش\u200cنویس فقط با تاریخ پاسخ (Reply) بدهید — دکمه لازم نیست. برای انصراف «لغو» بفرستید.',
    'The plugin’s own heartbeat: these recurring events keep schedules, the bot, and job runners working. They are managed automatically — listed here for transparency only.': 'ضربان خود افزونه: این رویدادهای تکرارشونده زمان\u200cبندی\u200cها، ربات و اجراکننده\u200cهای کار را سر پا نگه می\u200cدارند. مدیریتشان خودکار است — اینجا فقط برای شفافیت فهرست شده\u200cاند.',
    'Tomorrow 09:00': 'فردا ۰۹:۰۰',
    'Tomorrow 18:00': 'فردا ۱۸:۰۰',
    'Tonight 21:00': 'امشب ۲۱:۰۰',
    'Type': 'نوع',
    'Unfinished jobs': 'کارهای ناتمام',
    'View': 'مشاهده',
    'What it does': 'چه می\u200cکند',
    'once': 'یک\u200cبار',
    '⚡ Fastest: reply to a draft notification with just the date («فردا 18:30») — it is scheduled immediately, no button needed.': '⚡ سریع\u200cترین راه: به پیامِ پیش\u200cنویس فقط با تاریخ پاسخ (Reply) بدهید («فردا 18:30») — بلافاصله زمان\u200cبندی می\u200cشود، دکمه لازم نیست.',
    '🔘 Every draft notification has “Publish now” and “Schedule” buttons under it — “Schedule” offers one-tap times, or send a date yourself.': '🔘 زیر هر پیامِ پیش\u200cنویس دکمه\u200cهای «انتشار فوری» و «زمان\u200cبندی» هست — «زمان\u200cبندی» زمان\u200cهای یک\u200cلمسی پیشنهاد می\u200cدهد، یا خودتان تاریخ بفرستید.',
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
