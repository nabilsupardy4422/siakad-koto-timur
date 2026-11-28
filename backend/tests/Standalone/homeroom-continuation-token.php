<?php
require __DIR__.'/../../app/Support/HomeroomContinuationToken.php';
use App\Support\HomeroomContinuationToken as T;
$state=['actor'=>12,'date'=>'2026-11-27','source'=>1,'target'=>2,'versions'=>['old'],'rows'=>[['assignment_id'=>5,'class_id'=>6]]];
$key='synthetic-test-key'; $now=1800000000; $token=T::issue($state,$now,$key);
$cases=[
    'valid signature'=>T::verify($token,$state,$now,$key),
    'expired'=>!T::verify($token,$state,$now+301,$key),
    'wrong key'=>!T::verify($token,$state,$now,'other'),
    'missing key'=>!T::verify($token,$state,$now,''),
    'tampered token'=>!T::verify($token.'x',$state,$now,$key),
    'other actor'=>!T::verify($token,array_replace($state,['actor'=>13]),$now,$key),
    'other date'=>!T::verify($token,array_replace($state,['date'=>'2026-11-28']),$now,$key),
    'changed history fingerprint'=>!T::verify($token,array_replace($state,['versions'=>['old','new-cancelled']]),$now,$key),
    'changed mapping'=>!T::verify($token,array_replace($state,['rows'=>[['assignment_id'=>5,'class_id'=>7]]]),$now,$key),
    'clock before issue'=>!T::verify($token,$state,$now-1,$key),
];
$pass=0;foreach($cases as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;$pass+=(int)$ok;}
echo $pass.'/'.count($cases).' PASS'.PHP_EOL;exit($pass===count($cases)?0:1);
