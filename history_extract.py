#!/usr/bin/env python3
"""Извлекает все снимки data.json из истории git и складывает в history.json."""
import json, subprocess, statistics, sys, os

REPO = os.path.dirname(os.path.abspath(__file__))

def git(*args):
    return subprocess.run(['git', '-C', REPO, *args], capture_output=True, text=True).stdout

commits = []
for line in git('log', '--format=%H|%cI', '--', 'data.json').strip().splitlines():
    sha, iso = line.split('|')
    commits.append((sha, iso))
commits.reverse()  # от старых к новым

snaps = []
for sha, iso in commits:
    raw = git('show', f'{sha}:data.json')
    if not raw.strip():
        continue
    try:
        d = json.loads(raw)
    except Exception:
        continue
    dirs = {}
    total = 0
    people = {}          # code -> список (направление, форма, приоритет, балл)
    for x in d.get('directions', []):
        code = x['code']
        per = {}
        for f in ('budget', 'target', 'paid'):
            lst = x['forms'][f]['list']
            per[f] = len(lst)
            for a in lst:
                c = a.get('code')
                if not c:
                    continue
                people.setdefault(c, []).append({
                    'dir': code, 'name': x['name'], 'form': f,
                    'priority': a.get('priority'), 'score': a.get('score'),
                })
        cnt = sum(per.values())
        total += cnt
        dirs[code] = {'name': x['name'], 'per': per, 'total': cnt,
                      'stale': bool(x.get('stale')),
                      'places': {'budget': x.get('budgetEffective'),
                                 'target': x['forms']['target']['places'],
                                 'paid': x['forms']['paid']['places']}}
    snaps.append({'sha': sha, 'ts': iso, 'day': iso[:10],
                  'total': total, 'dirs': dirs, 'people': people})

# строгий хронологический порядок (git log может отдавать не по времени)
snaps.sort(key=lambda s: s['ts'])

# Заявления только накапливаются, поэтому подозрителен снимок, заметно просевший
# относительно предыдущего хорошего — это признак недокачанных отчётов.
prev_good = None
for s in snaps:
    s['suspect'] = prev_good is not None and s['total'] < prev_good * 0.9
    if not s['suspect']:
        prev_good = s['total']
med = statistics.median([s['total'] for s in snaps]) if snaps else 0

json.dump(snaps, open(os.path.join(REPO, 'history.json'), 'w'), ensure_ascii=False)

print(f"снимков: {len(snaps)}  медиана заявлений: {med:.0f}")
print("подозрительных (резкий провал):", sum(1 for s in snaps if s['suspect']))
for s in snaps:
    flag = ' <-- ПОДОЗРИТЕЛЬНЫЙ' if s['suspect'] else ''
    stale = sum(1 for v in s['dirs'].values() if v['stale'])
    print(f"  {s['ts'][:16]}  всего={s['total']:5}  напр={len(s['dirs']):3}  stale={stale:2}{flag}")
