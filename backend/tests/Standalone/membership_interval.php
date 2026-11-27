<?php
require __DIR__.'/../../app/Support/MembershipInterval.php';
use App\Support\MembershipInterval;
$cases=[['2026-12-01',null,'2026-11-01',true],['2026-12-01','2026-12-10','2026-12-10',true],['2026-12-01','2026-12-10','2026-12-11',false],['2026-12-01','2026-11-01','2026-12-11',true],[null,null,'2026-12-01',true],['2026-12-01','2026-12-01','2026-12-01',true],['2026-12-01','2026-12-01','2026-12-02',false]];
foreach($cases as $i=>[$s,$e,$n,$expected]){if(MembershipInterval::conflictsWithOpen($s,$e,$n)!==$expected){fwrite(STDERR,'FAIL '.($i+1).PHP_EOL);exit(1);}echo 'PASS '.($i+1).PHP_EOL;}
echo count($cases).'/'.count($cases).' pure interval cases passed'.PHP_EOL;
