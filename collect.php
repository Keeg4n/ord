<?php
/**
 * Сборщик рейтингов ординатуры КФУ -> data.json
 *
 *   php collect.php            # все направления ординатуры
 *   php collect.php 31.08.53 31.08.57   # только указанные коды (для отладки)
 *
 * Источники (sourceId): 1=Общие основания(бюджет), 2=Целевой, 3=Коммерция.
 * Форма отчёта в freports/list — всегда 2.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/fr_parse.php';

const BASE = 'https://abit.cfuv.ru';
const TRACK = '58490';               // абитуриент, чьи шансы считаем
$FORM_OF = [1 => 'budget', 2 => 'target', 3 => 'paid'];

$only = array_slice($argv, 1);       // фильтр по кодам направлений
$cookie = sys_get_temp_dir() . '/cfuv_collect.txt';

// ── HTTP ─────────────────────────────────────────────────────────────────────
function http(string $url, $post = null): ?array {
    global $cookie;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => '',
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0 Safari/537.36',
            'Accept: text/html,application/json,*/*',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . BASE . '/rating/specialties',
        ],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body === false ? null : ['code' => $code, 'body' => $body];
}

/**
 * Получить HTML отчёта FastReport для (specialtyId, sourceId).
 * Надёжно: в ответе freports/list бывает несколько 32-hex (id ресурсов, CSS-хэши),
 * поэтому перебираем всех кандидатов и оставляем только тот getReport, где реально
 * пришёл FastReport (в разметке есть его же классы fr{rid}s…), с ретраями.
 */
function fetch_report(int $spec, int $source): ?string {
    for ($try = 1; $try <= 2; $try++) {
        $list = http(BASE . "/api/freports/list/$spec/$source/2");
        if ($list && $list['code'] === 200 && preg_match_all('/[a-f0-9]{32}/i', $list['body'], $mm)) {
            foreach (array_unique($mm[0]) as $rid) {
                $rep = http(BASE . "/_fr/preview.getReport?reportId=$rid&renderBody=yes",
                            ['reportId' => $rid, 'renderBody' => 'yes']);
                if ($rep && $rep['code'] === 200 && strpos($rep['body'], "fr{$rid}s") !== false)
                    return $rep['body'];
            }
        }
        usleep(400000); // 0.4с перед повтором
    }
    return null;
}

/**
 * Разобрать HTML отчёта -> ['places'=>?int,'total'=>?int,'applicants'=>[...]].
 * Колонки определяем по left-координатам шапки (строка с «Уникальный код»).
 */
function parse_report(string $html): array {
    $rows = fr_parse_rows($html);
    $result = ['places' => null, 'total' => null, 'applicants' => []];

    // мета: «Количество мест: N», «Всего заявлений: N»
    foreach ($rows as $r) {
        $line = implode(' ', $r);
        if (preg_match('/Количество мест:\s*(\d+)/u', $line, $m)) $result['places'] = (int)$m[1];
        if (preg_match('/Всего заявлений:\s*(\d+)/u', $line, $m)) $result['total'] = (int)$m[1];
    }

    // найти строку-шапку и снять её ПОЛНУЮ версию с координатами
    $anchors = header_anchors($html); // [ [left, name], ... ] или null
    if (!$anchors) return $result;

    // строки данных: начинаются с порядкового номера (1..N) и содержат 5-значный код
    foreach ($cells_by_row = fr_parse_cells_by_row($html) as $cells) {
        // cells: [ ['left'=>,'text'=>], ... ] отсортированы по left
        $texts = array_map(fn($c) => $c['text'], $cells);
        $joined = implode(' ', $texts);
        // это строка абитуриента? есть 5-значный код и первая ячейка — число
        if (!preg_match('/^\d+$/', $texts[0] ?? '')) continue;
        $hasCode = false;
        foreach ($texts as $t) if (preg_match('/^\d{4,6}$/', $t) && (int)$t > 999) { $hasCode = true; break; }
        if (!$hasCode) continue;

        // привязка ячеек к колонкам по ближайшему якорю
        $rowByCol = [];
        foreach ($cells as $c) {
            $best = null; $bestD = 1e9;
            foreach ($anchors as $a) {
                $d = abs($c['left'] - $a['left']);
                if ($d < $bestD) { $bestD = $d; $best = $a['name']; }
            }
            // если в колонку уже что-то легло — дополняем (например число под под-шапкой)
            $rowByCol[$best] = isset($rowByCol[$best]) ? $rowByCol[$best] . ' ' . $c['text'] : $c['text'];
        }
        $result['applicants'][] = normalize_applicant($rowByCol, $texts);
    }
    return $result;
}

/** Якоря колонок: left-координаты ячеек строки-шапки. */
function header_anchors(string $html): ?array {
    foreach (fr_parse_cells_by_row($html) as $cells) {
        $joined = implode(' ', array_map(fn($c) => $c['text'], $cells));
        if (mb_strpos($joined, 'Уникальный код') !== false) {
            $out = [];
            foreach ($cells as $c) $out[] = ['left' => $c['left'], 'name' => $c['text']];
            return $out;
        }
    }
    return null;
}

/** Приводим строку к унифицированным полям. */
function normalize_applicant(array $byCol, array $texts): array {
    $code = null; $prio = null;
    // код — первое 4-6 значное число >999, не «сумма»
    foreach ($texts as $t) if (preg_match('/^\d{4,6}$/', $t) && (int)$t > 999) { $code = $t; break; }

    // сумма баллов (ВИ и ИД) — по имени колонки
    $sum = null;
    foreach ($byCol as $name => $val) {
        if (mb_stripos($name, 'Сумма баллов (ВИ') !== false) { $sum = first_int($val); }
    }
    // приоритет
    foreach ($byCol as $name => $val) {
        if (mb_stripos($name, 'Приоритет') !== false) $prio = first_int($val);
    }
    // согласие/оригинал
    $consent = '';
    foreach ($byCol as $name => $val) {
        if (mb_stripos($name, 'согласия') !== false || mb_stripos($name, 'оригинал') !== false) $consent = trim($val);
    }
    // другие направления
    $other = '';
    foreach ($byCol as $name => $val) {
        if (mb_stripos($name, 'Другие направления') !== false) $other = trim($val);
    }
    return ['code' => $code, 'score' => $sum, 'priority' => $prio, 'consent' => $consent, 'other' => $other];
}

function first_int($s): ?int { return preg_match('/-?\d+/', (string)$s, $m) ? (int)$m[0] : null; }

/** Вернуть строки как массив ячеек с координатами (для привязки). */
function fr_parse_cells_by_row(string $html): array {
    static $cache = [];
    $key = md5($html);
    if (isset($cache[$key])) return $cache[$key];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $cells = [];
    foreach ($xp->query('//div[contains(@style,"top:") and contains(@style,"left:")]') as $div) {
        $st = $div->getAttribute('style');
        if (!preg_match('/left:\s*([\d.]+)px/', $st, $l)) continue;
        if (!preg_match('/top:\s*([\d.]+)px/', $st, $t)) continue;
        $text = trim(preg_replace('/\s+/u', ' ',
            html_entity_decode($div->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '' || $text === "\xC2\xA0") continue;
        $cells[] = ['left' => (float)$l[1], 'top' => (float)$t[1], 'text' => $text];
    }
    usort($cells, fn($a, $b) => $a['top'] <=> $b['top'] ?: $a['left'] <=> $b['left']);
    $rows = []; $cur = null; $row = [];
    foreach ($cells as $c) {
        if ($cur === null || abs($c['top'] - $cur) > 8) { if ($row) $rows[] = $row; $row = []; $cur = $c['top']; }
        $row[] = $c;
    }
    if ($row) $rows[] = $row;
    return $cache[$key] = $rows;
}

// ── СБОР ─────────────────────────────────────────────────────────────────────
http(BASE . '/rating/specialties'); // прогрев сессии (нужен и для enroll)
$enrollRaw = http(BASE . '/api/specialties/enroll/2026/1/8');
if ($enrollRaw && $enrollRaw['code'] === 200 && ($j = json_decode($enrollRaw['body'], true)) && !empty($j['data'])) {
    file_put_contents(__DIR__ . '/enroll_1_8.json', $enrollRaw['body']);
    $enroll = $j['data'];
    fwrite(STDERR, "Список направлений обновлён (" . count($enroll) . " записей)\n");
} else {
    $enroll = json_decode(file_get_contents(__DIR__ . '/enroll_1_8.json'), true)['data'];
    fwrite(STDERR, "Использую кэш enroll_1_8.json\n");
}

// группируем по коду направления
$dirs = [];
foreach ($enroll as $s) {
    if (($s['levelId'] ?? null) != 8) continue;
    $code = $s['specialtyCode'];
    if ($only && !in_array($code, $only)) continue;
    $dirs[$code]['name'] = $s['specialtyName'];
    $dirs[$code]['code'] = $code;
    $dirs[$code]['entries'][] = [
        'id' => $s['specialtyId'],
        'budget' => (int)($s['budgetNo'] ?? 0),
        'target' => (int)($s['targetNo'] ?? 0),
        'paid'   => (int)($s['paidNo'] ?? 0),
    ];
}

$out = ['generatedAt' => date('c'), 'applicant' => TRACK, 'directions' => [], 'applicantHits' => []];

// Бюджет времени: за него точно уложимся в таймаут воркфлоу (25 мин).
// Что не успели собрать — берём из прошлого data.json (помечаем stale), чтобы не терять данные.
$START  = time();
$BUDGET = (int)(getenv('COLLECT_BUDGET') ?: 1000); // сек (~16.7 мин)
$prev = @json_decode(@file_get_contents(__DIR__ . '/data.json'), true);
$prevByCode = [];
if (is_array($prev) && !empty($prev['directions']))
    foreach ($prev['directions'] as $d) $prevByCode[$d['code']] = $d;
$carried = 0;

foreach ($dirs as $code => $dir) {
    // бюджет исчерпан — переносим направление из прошлого запуска
    if (time() - $START > $BUDGET) {
        if (isset($prevByCode[$code])) {
            $old = $prevByCode[$code];
            $old['stale'] = true;
            $out['directions'][] = $old;
            $carried++;
            fwrite(STDERR, ">> $code перенесено из прошлых данных (бюджет времени исчерпан)\n");
        }
        continue;
    }
    $forms = [
        'budget' => ['places' => 0, 'applicants' => 0, 'maxScore' => null, 'list' => []],
        'target' => ['places' => 0, 'applicants' => 0, 'maxScore' => null, 'list' => []],
        'paid'   => ['places' => 0, 'applicants' => 0, 'maxScore' => null, 'list' => []],
    ];
    $failed = false; // хоть один отчёт не скачался -> данным по направлению не доверяем
    // основное направление (для общих оснований/бюджета): с бюджетом, иначе с коммерцией
    $primary = null;
    foreach ($dir['entries'] as $e) if ($e['budget'] > 0) { $primary = $e['id']; break; }
    if ($primary === null) foreach ($dir['entries'] as $e) if ($e['paid'] > 0) { $primary = $e['id']; break; }
    if ($primary === null) $primary = $dir['entries'][0]['id'];

    foreach ($dir['entries'] as $e) {
        $queries = [];
        // источник 1 (общие основания/бюджет) — только на основном id, всегда,
        // т.к. незанятые целевые места 13.08 уходят именно сюда
        if ($e['id'] === $primary) $queries[] = [$e['id'], 1, 'budget'];
        if ($e['target'] > 0) $queries[] = [$e['id'], 2, 'target'];
        if ($e['paid']   > 0) $queries[] = [$e['id'], 3, 'paid'];
        foreach ($queries as [$id, $src, $form]) {
            $forms[$form]['places'] += ($form === 'budget' ? $e['budget'] : ($form === 'target' ? $e['target'] : $e['paid']));
            fwrite(STDERR, "  $code id=$id src=$src ($form)... ");
            usleep(250000); // 0.25с — вежливая пауза, чтобы не выглядеть как DoS
            $html = fetch_report($id, $src);
            if (!$html) { fwrite(STDERR, "нет отчёта\n"); $failed = true; continue; }
            $rep = parse_report($html);
            $apps = $rep['applicants'];
            fwrite(STDERR, count($apps) . " заявл.\n");
            foreach ($apps as $a) {
                $forms[$form]['list'][] = $a + ['entryId' => $id];
                if ($a['score'] !== null)
                    $forms[$form]['maxScore'] = max($forms[$form]['maxScore'] ?? -1, $a['score']);
            }
        }
    }
    foreach ($forms as $f => &$v) $v['applicants'] = count($v['list']);
    unset($v);

    // если сбор по направлению не удался — не затираем хорошие данные, берём прошлые
    if ($failed && isset($prevByCode[$code])) {
        $old = $prevByCode[$code];
        $old['stale'] = true;
        $out['directions'][] = $old;
        $carried++;
        fwrite(STDERR, ">> $code {$dir['name']}: отчёт не скачался — оставлены прошлые данные (stale)\n");
        continue;
    }

    // эффективный бюджет = бюджет + незанятые целевые места
    $targetPlaces = $forms['target']['places'];
    $targetTaken  = $forms['target']['applicants'];
    $freedTargets = max(0, $targetPlaces - $targetTaken);
    $budgetEff = $forms['budget']['places'] + $freedTargets;

    $out['directions'][] = [
        'code' => $code, 'name' => $dir['name'],
        'forms' => $forms,
        'freedTargets' => $freedTargets,
        'budgetEffective' => $budgetEff,
        'fetchedAt' => date('c'),
        'stale' => false,
    ];
    fwrite(STDERR, ">> $code {$dir['name']}: budget={$forms['budget']['places']} (eff $budgetEff) target=$targetPlaces paid={$forms['paid']['places']}\n");
}

// сохранить порядок направлений как в каталоге
usort($out['directions'], fn($a, $b) => strcmp($a['code'], $b['code']));

// попадания отслеживаемого абитуриента — пересчитываем по финальному набору (вкл. перенесённые)
$out['applicantHits'] = [];
foreach ($out['directions'] as $d) {
    foreach (['budget', 'target', 'paid'] as $f) {
        foreach ($d['forms'][$f]['list'] as $a) {
            if (($a['code'] ?? null) === TRACK) {
                $out['applicantHits'][] = [
                    'direction' => $d['name'], 'code' => $d['code'], 'form' => $f,
                    'score' => $a['score'], 'priority' => $a['priority'],
                    'consent' => $a['consent'], 'other' => $a['other'], 'entryId' => $a['entryId'] ?? null,
                ];
            }
        }
    }
}

$fresh = count($out['directions']) - $carried;
file_put_contents(__DIR__ . '/data.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fwrite(STDERR, "\nСохранено: data.json — направлений " . count($out['directions'])
    . " (свежих $fresh, перенесённых $carried), попаданий " . TRACK . ": "
    . count($out['applicantHits']) . "\n");
