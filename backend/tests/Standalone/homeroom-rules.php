<?php
require __DIR__.'/../../app/Support/HomeroomAssignmentRules.php';
use App\Support\HomeroomAssignmentRules as R;
$today = '2026-11-27';
$cases = [
    'effective today' => R::status($today,null,$today)==='effective',
    'future scheduled' => R::status('2026-11-28',null,$today)==='scheduled',
    'closed today' => R::status('2026-11-01',$today,$today)==='history',
    'future closure anomalous' => R::status($today,'2026-11-28',$today)==='conflict',
    'reversed dates' => R::invalid($today,'2026-11-01',$today),
    'missing start' => R::invalid(null,null,$today),
    'two open intervals' => R::overlaps('2026-11-01',null,'2026-11-28',null),
    'historical collision' => R::overlaps('2026-11-01','2026-11-20','2026-11-10',null),
    'boundary handover' => !R::overlaps('2026-11-01',$today,$today,null),
    'same-day closure' => !R::overlaps($today,$today,$today,null),
];
$fail=0;
foreach($cases as $name=>$passed){echo ($passed?'PASS ':'FAIL ').$name.PHP_EOL; if(!$passed)$fail++;}
echo (count($cases)-$fail).'/'.count($cases).' PASS'.PHP_EOL;
exit($fail?1:0);
