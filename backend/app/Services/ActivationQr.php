<?php

namespace App\Services;

/** Bounded QR Model 2, byte mode, error correction M, versions 1–12.
 * Only encodes the configured public activation URL. No network, GD, or secret data.
 * PNG uses zlib and a four-module quiet zone. Reference matrices independently tested.
 */
final class ActivationQr
{
    private const BLOCKS = [
        1=>[[1,26,16]], 2=>[[1,44,28]], 3=>[[1,70,44]], 4=>[[2,50,32]],
        5=>[[2,67,43]], 6=>[[4,43,27]], 7=>[[4,49,31]], 8=>[[2,60,38],[2,61,39]],
        9=>[[3,58,36],[2,59,37]], 10=>[[4,69,43],[1,70,44]],
        11=>[[1,80,50],[4,81,51]], 12=>[[6,58,36],[2,59,37]],
    ];
    private const ALIGN = [1=>[],2=>[6,18],3=>[6,22],4=>[6,26],5=>[6,30],6=>[6,34],7=>[6,22,38],8=>[6,24,42],9=>[6,26,46],10=>[6,28,50],11=>[6,30,54],12=>[6,32,58]];

    private static function multiply(int $a, int $b): int
    {
        $r=0;
        while ($b) { if ($b & 1) $r ^= $a; $b >>= 1; $a <<= 1; if ($a & 256) $a ^= 0x11d; }
        return $r;
    }
    private static function ecc(array $data, int $degree): array
    {
        $g=[1]; $root=1;
        for ($i=0;$i<$degree;$i++) {
            $next=array_fill(0,count($g)+1,0);
            foreach ($g as $j=>$v) { $next[$j]^=$v; $next[$j+1]^=self::multiply($v,$root); }
            $g=$next; $root=self::multiply($root,2);
        }
        $r=array_fill(0,$degree,0);
        foreach ($data as $b) {
            $factor=$b ^ array_shift($r); $r[]=0;
            for ($j=0;$j<$degree;$j++) $r[$j]^=self::multiply($g[$j+1],$factor);
        }
        return $r;
    }
    private static function bits(int $value, int $length): string { return str_pad(decbin($value),$length,'0',STR_PAD_LEFT); }
    private static function bch(int $value, int $degree, int $polynomial): int
    {
        $r=$value << $degree;
        for ($i=strlen(decbin($r))-1;$i >= $degree;$i--) if (($r >> $i)&1) $r ^= $polynomial << ($i-$degree);
        return ($value << $degree) | $r;
    }
    public static function matrix(string $text): array
    {
        $len=strlen($text);
        if ($len<1 || $len>250 || preg_match('/[^\x21-\x7e]/',$text)) throw new \InvalidArgumentException('Invalid QR payload');
        foreach (self::BLOCKS as $version=>$groups) {
            $capacity=array_sum(array_map(fn($g)=>$g[0]*$g[2],$groups));
            if (4+($version<10?8:16)+8*$len <= $capacity*8) break;
        }
        $bits='0100'.self::bits($len,$version<10?8:16);
        foreach (str_split($text) as $b) $bits.=self::bits(ord($b),8);
        $bits.=str_repeat('0',min(4,$capacity*8-strlen($bits)));
        $bits.=str_repeat('0',(8-strlen($bits)%8)%8);
        $data=array_map('bindec',str_split($bits,8));
        for ($i=0;count($data)<$capacity;$i++) $data[]=$i%2?0x11:0xec;
        $blocks=[];$checks=[];$offset=0;
        foreach ($groups as [$count,$total,$size]) for ($i=0;$i<$count;$i++) {
            $block=array_slice($data,$offset,$size);$offset+=$size;$blocks[]=$block;$checks[]=self::ecc($block,$total-$size);
        }
        $stream='';
        foreach ([$blocks,$checks] as $group) for ($i=0;$i<max(array_map('count',$group));$i++) foreach ($group as $block) if (isset($block[$i])) $stream.=self::bits($block[$i],8);
        $n=17+4*$version;$base=array_fill(0,$n,array_fill(0,$n,null));
        foreach ([[0,0],[0,$n-7],[$n-7,0]] as [$r,$c]) for ($y=-1;$y<=7;$y++) for ($x=-1;$x<=7;$x++) {
            if ($r+$y<0||$r+$y>=$n||$c+$x<0||$c+$x>=$n) continue;
            $base[$r+$y][$c+$x]=(int)($x>=0&&$x<=6&&$y>=0&&$y<=6&&($x===0||$x===6||$y===0||$y===6||($x>=2&&$x<=4&&$y>=2&&$y<=4)));
        }
        foreach (self::ALIGN[$version] as $r) foreach (self::ALIGN[$version] as $c) {
            if ($base[$r][$c]!==null) continue;
            for ($y=-2;$y<=2;$y++) for ($x=-2;$x<=2;$x++) $base[$r+$y][$c+$x]=(int)(max(abs($x),abs($y))!==1);
        }
        for ($i=8;$i<$n-8;$i++) {
            if ($base[6][$i]===null) $base[6][$i]=(int)($i%2===0);
            if ($base[$i][6]===null) $base[$i][6]=(int)($i%2===0);
        }
        if ($version>=7) {
            $v=self::bch($version,12,0x1f25);
            for ($i=0;$i<18;$i++) $base[intdiv($i,3)][$i%3+$n-11]=$base[$i%3+$n-11][intdiv($i,3)]=($v>>$i)&1;
        }
        $best=null;$score=PHP_INT_MAX;
        for ($mask=0;$mask<8;$mask++) {
            $m=$base;$format=self::bch($mask,10,0x537)^0x5412; // M has format bits 00.
            for ($i=0;$i<15;$i++) {
                $bit=($format>>$i)&1;
                $m[$i<6?$i:($i<8?$i+1:$n-15+$i)][8]=$bit;
                $m[8][$i<8?$n-$i-1:($i===8?7:14-$i)]=$bit;
            }
            $m[$n-8][8]=1;$p=0;$up=true;
            for ($c=$n-1;$c>0;$c-=2) {
                if ($c===6) $c--;
                for ($i=0;$i<$n;$i++) {
                    $r=$up?$n-1-$i:$i;
                    for ($x=$c;$x>=$c-1;$x--) {
                        if ($m[$r][$x]!==null) continue;
                        $bit=(int)($stream[$p++]??'0');$product=$r*$x;
                        $flip=match($mask){0=>($r+$x)%2===0,1=>$r%2===0,2=>$x%3===0,3=>($r+$x)%3===0,4=>(intdiv($r,2)+intdiv($x,3))%2===0,5=>$product%2+$product%3===0,6=>($product%2+$product%3)%2===0,7=>($product%3+($r+$x)%2)%2===0};
                        $m[$r][$x]=$bit ^ (int)$flip;
                    }
                }
                $up=!$up;
            }
            $penalty=self::penalty($m);
            if ($penalty<$score) { $best=$m;$score=$penalty; }
        }
        return $best;
    }
    private static function penalty(array $m): int
    {
        $n=count($m);$score=0;$dark=0;
        for ($i=0;$i<$n;$i++) {
            $row=implode('',$m[$i]);$col='';
            for ($j=0;$j<$n;$j++) {
                $col.=$m[$j][$i];$dark+=$m[$i][$j];
                if ($i&&$j&&$m[$i][$j]===$m[$i-1][$j]&&$m[$i][$j]===$m[$i][$j-1]&&$m[$i][$j]===$m[$i-1][$j-1]) $score+=3;
            }
            foreach ([$row,$col] as $line) {
                preg_match_all('/0{5,}|1{5,}/',$line,$runs);foreach ($runs[0] as $run) $score+=strlen($run)-2;
                $score+=40*(substr_count($line,'00001011101')+substr_count($line,'10111010000'));
            }
        }
        return $score+10*(int)floor(abs(100*$dark/($n*$n)-50)/5);
    }
    public static function pngDataUri(string $text): string
    {
        $matrix=self::matrix($text);$n=count($matrix);$scale=6;$size=($n+8)*$scale;$raw='';
        for ($y=-4;$y<$n+4;$y++) {
            $row="\0";
            for ($x=-4;$x<$n+4;$x++) $row.=str_repeat(($matrix[$y][$x]??0)?"\0":"\xff",$scale);
            $raw.=str_repeat($row,$scale);
        }
        $chunk=fn($type,$bytes)=>pack('N',strlen($bytes)).$type.$bytes.pack('N',crc32($type.$bytes));
        $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$size,$size,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
        return 'data:image/png;base64,'.base64_encode($png);
    }
}
