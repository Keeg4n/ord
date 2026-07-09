<?php
/**
 * Выгрузка рейтинга абитуриентов КФУ (abit.cfuv.ru).
 *
 * Повторяет поведение браузера:
 *   1) GET  /api/freports/list/{spec}/{source}/{form}  -> отдаёт HTML FastReport, внутри reportId
 *   2) POST /_fr/preview.getReport?reportId=...&renderBody=yes -> сама таблица рейтинга (HTML)
 *
 * Оба запроса идут в ОДНОЙ сессии (общий cookie-jar) — иначе reportId невалиден.
 *
 * Запуск:
 *   php cfuv_rating.php 2142 3 2
 *   (spec=2142, source=3 — как в ссылке #3, form=2)
 *
 * Результат: report_{spec}_{source}_{form}.html + попытка распарсить в CSV.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$BASE = 'https://abit.cfuv.ru';

// --- аргументы ---------------------------------------------------------------
$spec   = $argv[1] ?? '2142';
$source = $argv[2] ?? '3';
$form   = $argv[3] ?? '2';

$cookieJar = sys_get_temp_dir() . '/cfuv_cookies_' . getmypid() . '.txt';

// --- общая обёртка cURL ------------------------------------------------------
function http($url, $post = null, $extraHeaders = [], $cookieJar = null, $referer = '') {
    $ch = curl_init($url);
    $headers = array_merge([
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'Accept: text/html,application/json,*/*',
        'Accept-Language: ru,en;q=0.9',
        'X-Requested-With: XMLHttpRequest',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',          // принимать gzip/deflate
    ]);
    if ($referer)   curl_setopt($ch, CURLOPT_REFERER, $referer);
    if ($cookieJar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                      curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar); }

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        // FastReport ждёт form-urlencoded тело
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
    }

    $body = curl_exec($ch);
    if ($body === false) {
        fwrite(STDERR, "cURL error: " . curl_error($ch) . "\n");
        curl_close($ch);
        return null;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

// --- шаг 0: «прогреть» сессию, зайдя на страницу рейтинга --------------------
$pageUrl = "$BASE/rating/$spec";
http($pageUrl, null, [], $cookieJar);

// --- шаг 1: получить HTML отчёта и вытащить reportId -------------------------
$listUrl = "$BASE/api/freports/list/$spec/$source/$form";
echo "[1] GET $listUrl\n";
$r = http($listUrl, null, ['Accept: text/html,*/*'], $cookieJar, $pageUrl);
if (!$r || $r['code'] !== 200) {
    exit("Не удалось получить список отчётов (HTTP " . ($r['code'] ?? '?') . ")\n");
}

// reportId — 32 hex-символа. Берём первый попавшийся.
if (!preg_match('/[a-f0-9]{32}/i', $r['body'], $m)) {
    file_put_contents("freports_list_raw_$spec.html", $r['body']);
    exit("reportId не найден. Сырой ответ сохранён в freports_list_raw_$spec.html — "
       . "загляни туда, id может иметь другой формат.\n");
}
$reportId = $m[0];
echo "[1] reportId = $reportId\n";

// --- шаг 2: POST getReport --------------------------------------------------
$repUrl = "$BASE/_fr/preview.getReport?reportId=$reportId&renderBody=yes";
echo "[2] POST $repUrl\n";
$r = http(
    $repUrl,
    ['reportId' => $reportId, 'renderBody' => 'yes'],
    ['Accept: text/html,*/*', 'Content-Type: application/x-www-form-urlencoded'],
    $cookieJar,
    $pageUrl
);
if (!$r || $r['code'] !== 200) {
    exit("Не удалось получить отчёт (HTTP " . ($r['code'] ?? '?') . ")\n");
}

$outHtml = "report_{$spec}_{$source}_{$form}.html";
file_put_contents($outHtml, $r['body']);
echo "[2] HTML отчёта сохранён: $outHtml (" . strlen($r['body']) . " байт)\n";

// --- шаг 3: извлечение ячеек отчёта -----------------------------------------
$cells = parseFastReportCells($r['body']);
if ($cells) {
    $txt = "report_{$spec}_{$source}_{$form}.txt";
    file_put_contents($txt, implode("\n", $cells) . "\n");
    echo "[3] Извлечено ячеек: " . count($cells) . " -> $txt\n\n";

    // Красивый вывод в консоль
    echo "──────── СОДЕРЖИМОЕ ОТЧЁТА ────────\n";
    echo implode("\n", $cells), "\n";
} else {
    echo "[3] Ячейки не найдены — открой $outHtml вручную.\n";
}

// Чистим cookie
@unlink($cookieJar);

/**
 * FastReport Web рендерит каждую ячейку как <div class="fr{reportId}s{N}">текст</div>
 * в порядке чтения (шапка -> строки сверху вниз, слева направо).
 * Возвращаем плоский список непустых ячеек — надёжнее, чем угадывать колонки.
 */
function parseFastReportCells(string $html): array {
    if (!preg_match_all('/<div class="fr[a-f0-9]{32}s\d+"[^>]*>(.*?)<\/div>/is',
                        $html, $m)) return [];
    $out = [];
    foreach ($m[1] as $t) {
        $t = cleanCell($t);
        // 'String' — служебный маркер FastReport, пропускаем
        if ($t !== '' && $t !== 'String') $out[] = $t;
    }
    return $out;
}

function cleanCell(string $s): string {
    $s = preg_replace('/<br\s*\/?>/i', ' ', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}
