#!/usr/bin/env python3
"""Строит stats.html из history.json — со срезами по формам обучения."""
import json, os, collections

REPO = os.path.dirname(os.path.abspath(__file__))
snaps = json.load(open(os.path.join(REPO, 'history.json')))

good = [s for s in snaps if not s['suspect']]
excluded = [s for s in snaps if s['suspect']]

# последний хороший снимок каждого дня
by_day = {}
for s in good:
    by_day[s['day']] = s
days = sorted(by_day)

FORMS = ['budget', 'target', 'paid']
FORM_RU = {'all': 'Все формы', 'budget': 'Бюджет', 'target': 'Целевое', 'paid': 'Коммерция'}
KEYS = ['all'] + FORMS

dir_names = {}
for s in good:
    for c, v in s['dirs'].items():
        dir_names[c] = v['name']


def count(snap, code, form):
    v = snap['dirs'].get(code)
    if not v:
        return 0
    return v['total'] if form == 'all' else v['per'][form]


def people_dirs(snap, form):
    """{код: множество направлений} в рамках выбранной формы."""
    out = {}
    for code, entries in snap['people'].items():
        ds = {e['dir'] for e in entries if form == 'all' or e['form'] == form}
        if ds:
            out[code] = ds
    return out


def last_score(snap, code, form):
    vals = [e['score'] for e in snap['people'].get(code, [])
            if (form == 'all' or e['form'] == form) and e['score'] is not None]
    return max(vals) if vals else None


by_form = {}
for form in KEYS:
    totals, dirmat = [], {c: [] for c in dir_names}
    for d in days:
        s = by_day[d]
        tot = 0
        for c in dir_names:
            v = count(s, c, form)
            dirmat[c].append(v)
            tot += v
        totals.append(tot)

    # движения
    arrivals, departures = [0], [0]
    mover_events = collections.defaultdict(list)
    all_codes = set()
    for i, d in enumerate(days):
        cur = people_dirs(by_day[d], form)
        all_codes |= set(cur)
        if i == 0:
            continue
        prev = people_dirs(by_day[days[i - 1]], form)
        arrivals.append(len(set(cur) - set(prev)))
        departures.append(len(set(prev) - set(cur)))
        for code in set(prev) & set(cur):
            a, b = prev[code], cur[code]
            if a != b:
                mover_events[code].append(
                    {'day': d, 'added': sorted(b - a), 'dropped': sorted(a - b)})

    movers = []
    last = by_day[days[-1]]
    for code, evs in mover_events.items():
        movers.append({
            'code': code, 'events': evs, 'n': len(evs),
            'now': sorted(people_dirs(last, form).get(code, [])),
            'score': last_score(last, code, form),
        })
    movers.sort(key=lambda m: (-m['n'], m['code']))

    rows = []
    for c in sorted(dir_names):
        r = dirmat[c]
        rows.append({'code': c, 'name': dir_names[c], 'counts': r,
                     'last': r[-1], 'delta': r[-1] - r[0]})
    rows.sort(key=lambda r: -r['last'])

    by_form[form] = {
        'totals': totals, 'dirRows': rows,
        'arrivals': arrivals, 'departures': departures,
        'movers': movers,
        'stats': {'totalNow': totals[-1] if totals else 0,
                  'growth': (totals[-1] - totals[0]) if totals else 0,
                  'people': len(all_codes), 'movers': len(movers),
                  'directions': sum(1 for r in rows if r['last'])},
    }

payload = {
    'days': days, 'keys': KEYS, 'formRu': FORM_RU,
    'byForm': by_form, 'dirNames': dir_names,
    'seriesAll': {f: by_form[f]['totals'] for f in FORMS},
    'meta': {'snapshots': len(snaps), 'used': len(good), 'excluded': len(excluded),
             'excludedList': [s['ts'][:16] for s in excluded],
             'period': [days[0], days[-1]] if days else ['', ''],
             'lastSnap': good[-1]['ts'][:16] if good else '',
             'directions': len(dir_names)},
}

html = open(os.path.join(REPO, 'stats_template.html'), encoding='utf-8').read()
html = html.replace('/*__DATA__*/null', json.dumps(payload, ensure_ascii=False))
open(os.path.join(REPO, 'stats.html'), 'w', encoding='utf-8').write(html)

print(f"дней: {len(days)} ({payload['meta']['period'][0]}..{payload['meta']['period'][1]})")
for f in KEYS:
    st = by_form[f]['stats']
    print(f"  {FORM_RU[f]:<12} сейчас={st['totalNow']:5} прирост=+{st['growth']:<5} "
          f"людей={st['people']:4} сменили={st['movers']}")
print("stats.html записан")
