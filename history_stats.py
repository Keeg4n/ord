#!/usr/bin/env python3
"""Строит stats.html из history.json (снимки data.json из истории git)."""
import json, os, collections

REPO = os.path.dirname(os.path.abspath(__file__))
snaps = json.load(open(os.path.join(REPO, 'history.json')))

good = [s for s in snaps if not s['suspect']]
excluded = [s for s in snaps if s['suspect']]

# ── последний хороший снимок каждого дня ────────────────────────────────────
by_day = {}
for s in good:
    by_day[s['day']] = s          # снимки уже по возрастанию времени
days = sorted(by_day)

# ── ряды по формам и итог ───────────────────────────────────────────────────
FORMS = ['budget', 'target', 'paid']
FORM_RU = {'budget': 'Бюджет', 'target': 'Целевое', 'paid': 'Коммерция'}
series = {f: [] for f in FORMS}
totals = []
for d in days:
    s = by_day[d]
    per = {f: 0 for f in FORMS}
    for v in s['dirs'].values():
        for f in FORMS:
            per[f] += v['per'][f]
    for f in FORMS:
        series[f].append(per[f])
    totals.append(s['total'])

# ── направления × дни ───────────────────────────────────────────────────────
dir_names = {}
for s in good:
    for c, v in s['dirs'].items():
        dir_names[c] = v['name']
dir_rows = []
for c in sorted(dir_names):
    row = [by_day[d]['dirs'].get(c, {}).get('total', 0) for d in days]
    dir_rows.append({'code': c, 'name': dir_names[c], 'counts': row,
                     'last': row[-1] if row else 0,
                     'delta': (row[-1] - row[0]) if row else 0})
dir_rows.sort(key=lambda r: -r['last'])

# ── движения абитуриентов ───────────────────────────────────────────────────
def dirset(snap, code):
    return {e['dir'] for e in snap['people'].get(code, [])}

changes, arrivals, departures = [], collections.Counter(), collections.Counter()
mover_events = collections.defaultdict(list)

for i in range(1, len(days)):
    prev, cur = by_day[days[i-1]], by_day[days[i]]
    pcodes, ccodes = set(prev['people']), set(cur['people'])
    arrivals[days[i]] = len(ccodes - pcodes)
    departures[days[i]] = len(pcodes - ccodes)
    for code in pcodes & ccodes:
        a, b = dirset(prev, code), dirset(cur, code)
        if a != b:
            added, dropped = b - a, a - b
            if added or dropped:
                mover_events[code].append({
                    'day': days[i],
                    'added': sorted(added), 'dropped': sorted(dropped),
                })

movers = []
for code, evs in mover_events.items():
    last = by_day[days[-1]]['people'].get(code, [])
    movers.append({
        'code': code, 'events': evs, 'n': len(evs),
        'now': sorted({e['dir'] for e in last}),
        'score': max([e['score'] for e in last if e['score'] is not None], default=None),
    })
movers.sort(key=lambda m: (-m['n'], m['code']))

all_codes = set()
for d in days:
    all_codes |= set(by_day[d]['people'])

payload = {
    'days': days, 'series': series, 'totals': totals,
    'formRu': FORM_RU,
    'dirRows': dir_rows,
    'movers': movers,
    'arrivals': [arrivals.get(d, 0) for d in days],
    'departures': [departures.get(d, 0) for d in days],
    'dirNames': dir_names,
    'stats': {
        'snapshots': len(snaps), 'used': len(good), 'excluded': len(excluded),
        'excludedList': [s['ts'][:16] for s in excluded],
        'period': [days[0], days[-1]] if days else ['', ''],
        'totalNow': totals[-1] if totals else 0,
        'growth': (totals[-1] - totals[0]) if totals else 0,
        'people': len(all_codes),
        'movers': len(movers),
        'directions': len(dir_names),
    },
}

html = open(os.path.join(REPO, 'stats_template.html'), encoding='utf-8').read()
html = html.replace('/*__DATA__*/null', json.dumps(payload, ensure_ascii=False))
open(os.path.join(REPO, 'stats.html'), 'w', encoding='utf-8').write(html)

st = payload['stats']
print(f"дней: {len(days)} ({st['period'][0]}..{st['period'][1]})")
print(f"снимков: {st['snapshots']} (использовано {st['used']}, исключено {st['excluded']})")
print(f"заявлений сейчас: {st['totalNow']}  прирост: +{st['growth']}")
print(f"уникальных абитуриентов: {st['people']}  сменили направление: {st['movers']}")
print("stats.html записан")
