<?php
namespace App\Support;
final class AcademicTransitionRules {
    public static function nextPeriod(array $from, array $to): ?string {
        if ($from['semester']==='GANJIL' && $to['semester']==='GENAP' && $from['tahun_mulai']===$to['tahun_mulai'] && $from['tahun_selesai']===$to['tahun_selesai']) return 'semester';
        if ($from['semester']==='GENAP' && $to['semester']==='GANJIL' && $to['tahun_mulai']===$from['tahun_mulai']+1 && $to['tahun_selesai']===$from['tahun_selesai']+1) return 'year';
        return null;
    }
    public static function allows(string $action, int $from, ?int $to, ?string $mode): bool {
        return match($action) {
            'move' => $mode==='same' && $from===$to,
            'continue' => $mode==='semester' && $from===$to,
            'promote' => $mode==='year' && $from<12 && $to===$from+1,
            'retain' => $mode==='year' && $from===$to,
            'graduate' => $mode==='year' && $from===12 && $to===null,
            'withdraw' => $to===null && in_array($mode,['same','semester','year'],true),
            default => false,
        };
    }
    public static function overlap(?string $a, ?string $b, ?string $c, ?string $d): bool {
        return !$a || !$c || ($b!==null && $b<$a) || ($d!==null && $d<$c) || (($b===null || $b >= $c) && ($d===null || $d >= $a));
    }
}
