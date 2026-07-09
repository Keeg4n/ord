<?php
/**
 * Парсер отчёта FastReport Web в строки таблицы по координатам.
 * Каждая ячейка — <div style="left:Xpx;top:Ypx;...">текст</div>.
 * Группируем по top (строки), сортируем по left (колонки).
 */
function fr_parse_rows(string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $cells = [];
    foreach ($xp->query('//div[contains(@style,"top:") and contains(@style,"left:")]') as $div) {
        $style = $div->getAttribute('style');
        if (!preg_match('/left:\s*([\d.]+)px/', $style, $l)) continue;
        if (!preg_match('/top:\s*([\d.]+)px/', $style, $t)) continue;
        $text = trim(preg_replace('/\s+/u', ' ',
            html_entity_decode($div->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '' || $text === "\xC2\xA0") continue; // пусто / &nbsp;
        $cells[] = ['left' => (float)$l[1], 'top' => (float)$t[1], 'text' => $text];
    }

    // группировка по top с допуском
    usort($cells, fn($a, $b) => $a['top'] <=> $b['top'] ?: $a['left'] <=> $b['left']);
    $rows = [];
    $curTop = null; $row = [];
    foreach ($cells as $c) {
        if ($curTop === null || abs($c['top'] - $curTop) > 8) {
            if ($row) $rows[] = $row;
            $row = []; $curTop = $c['top'];
        }
        $row[] = $c;
    }
    if ($row) $rows[] = $row;

    // отсортировать каждую строку по left и вернуть только текст
    $out = [];
    foreach ($rows as $r) {
        usort($r, fn($a, $b) => $a['left'] <=> $b['left']);
        $out[] = array_map(fn($c) => $c['text'], $r);
    }
    return $out;
}

if (isset($argv[1]) && realpath($argv[0]) === realpath(__FILE__)) {
    foreach (fr_parse_rows(file_get_contents($argv[1])) as $i => $r) {
        echo str_pad($i, 3), ' | ', implode('  ||  ', $r), "\n";
    }
}
