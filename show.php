<?php
$d = json_decode(file_get_contents(__DIR__.'/data.json'), true);
echo "ПОПАДАНИЯ АБИТУРИЕНТА {$d['applicant']}:\n";
$byCode = [];
foreach ($d['directions'] as $x) $byCode[$x['code']] = $x;
foreach ($d['applicantHits'] as $h) {
    $dir = $byCode[$h['code']];
    $list = $dir['forms'][$h['form']]['list'];
    usort($list, fn($a,$b)=>($b['score']??-1)-($a['score']??-1));
    $rank = 0; foreach ($list as $i=>$a) if ($a['code']===$d['applicant']) { $rank=$i+1; break; }
    $places = $h['form']==='budget' ? $dir['budgetEffective'] : $dir['forms'][$h['form']]['places'];
    printf("  %-16s %-8s балл=%-4s приоритет=%s  место %d из %d  %s | др: %s\n",
        $h['direction'], $h['form'], $h['score']??'-', $h['priority']??'-',
        $rank, $places, $rank && $rank<=$places ? 'ПРОХОДИТ':'нет', $h['other']);
}
echo "\nЭНДОКРИНОЛОГИЯ / ОНКОЛОГИЯ — расклад по формам:\n";
foreach (['31.08.53','31.08.57'] as $c) {
    $x = $byCode[$c];
    echo "  {$x['name']} ({$c}): бюджет объявл={$x['forms']['budget']['places']} +целевых свободно={$x['freedTargets']} => эфф.бюджет={$x['budgetEffective']}; ".
         "целевое мест={$x['forms']['target']['places']}/заявл={$x['forms']['target']['applicants']}; ".
         "коммерция мест={$x['forms']['paid']['places']}/заявл={$x['forms']['paid']['applicants']}\n";
}
