<?php
namespace App\Services;

use App\Services\TuAcademicReadService as Read;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class TuAttendanceDataset
{
    private static function projection(array $summary,array $rows,array $warnings): array
    {
        return ['summary'=>$summary,'rows'=>array_values($rows),'warnings'=>array_values(array_unique($warnings))];
    }
    public static function build(array $c): array
    {
        extract($c);
        if($d['view']==='daily') abort_if(isset($d['subject_id'])||isset($d['teacher_id'])||isset($d['schedule_id']),422,'Rekap harian mencakup seluruh jadwal rombel.');
        $allMembers=Read::members($classes->pluck('id'));
        $warnings=[];
        if($allMembers->contains(fn($m)=>!Read::validInterval($m))) $warnings[]='Terdapat interval anggota kelas tidak valid; tidak digunakan dalam rekap.';
        $members=$allMembers->filter(fn($m)=>Read::validInterval($m)&&$m->tanggal_mulai<=$date&&($m->tanggal_selesai===null||$m->tanggal_selesai>=$date));
        foreach($members->groupBy('siswa_id') as $group) if($group->count()>1){$warnings[]='Terdapat keanggotaan efektif ganda pada periode ini. Total merupakan jumlah siswa per rombel, bukan siswa unik sekolah.';break;}
        $roster=$members->groupBy('kelas_id')->map(fn($g)=>$g->keyBy('siswa_id'));
        $records=Read::bounded(DB::table('presensi as p')->join('siswa as s','s.id','=','p.siswa_id')->whereIn('p.jadwal_pelajaran_id',$scoped->pluck('id'))->whereDate('p.tanggal',$date)->select('p.*','s.nama_lengkap','s.nisn','s.nipd','s.nis')->orderBy('p.id'),30000);
        $bySchedule=$scoped->keyBy('id');$valid=[];$details=[];
        $duplicate=$records->groupBy(fn($r)=>$r->jadwal_pelajaran_id.':'.$r->siswa_id)->filter(fn($g)=>$g->count()>1);
        foreach($records as $r){$s=$bySchedule[$r->jadwal_pelajaran_id];$key=$s->id.':'.$r->siswa_id;$ok=(!($c['actual_only']??false)||$date<=$now->toDateString())&&isset($roster[$s->kelas_id][$r->siswa_id])&&in_array(strtoupper((string)$r->status),['HADIR','IZIN','SAKIT','ALPA'],true)&&!isset($duplicate[$key]);
            if($ok)$valid[$s->id][$r->siswa_id]=strtoupper((string)$r->status);else $warnings[]='Ada catatan di luar roster, status tidak valid, atau duplikat; tidak dihitung pada rekap.';
            $details[]=['id'=>(int)$r->id,...Read::student($r),'class_id'=>(int)$s->kelas_id,'class_name'=>$s->class_name,'subject_name'=>$s->subject_name,'teacher_name'=>$s->teacher_name,'schedule_id'=>(int)$s->id,'date'=>$date,'status'=>$ok?strtoupper((string)$r->status):$r->status,'note'=>$r->catatan,'integrity'=>$ok?'Valid':'Anomali'];
        }
        if($d['view']==='detail'){
            $rows=array_values(array_filter($details,fn($r)=>Read::matches($r,trim($d['search']??''))));
            return self::projection(['Catatan ditemukan'=>count($rows),'Catatan valid'=>count(array_filter($rows,fn($r)=>$r['integrity']==='Valid')),'Anomali'=>count(array_filter($rows,fn($r)=>$r['integrity']==='Anomali'))],$rows,$warnings);
        }
        if($d['view']==='daily'){
            $rows=[];$summary=['Total siswa per rombel'=>0,'Hadir'=>0,'Izin'=>0,'Sakit'=>0,'Alpa'=>0,'Belum ada data'=>0];
            foreach($selected as $class){$counts=['Hadir'=>0,'Izin'=>0,'Sakit'=>0,'Alpa'=>0,'Belum ada data'=>0];$statuses=[];
                foreach($scoped->where('kelas_id',$class->id) as $s)foreach($valid[$s->id]??[] as $id=>$status)$statuses[$id][]=$status;
                foreach($roster[$class->id]??[] as $id=>$m){$label='Belum ada data';foreach(['ALPA'=>'Alpa','SAKIT'=>'Sakit','IZIN'=>'Izin','HADIR'=>'Hadir'] as $key=>$name)if(in_array($key,$statuses[$id]??[],true)){$label=$name;break;}$counts[$label]++;}
                $total=array_sum($counts);$summary['Total siswa per rombel']+=$total;foreach($counts as $k=>$v)$summary[$k]+=$v;
                $rows[]=['id'=>(int)$class->id,'class_id'=>(int)$class->id,'class_name'=>$class->nama,'level'=>(int)$class->tingkat,'total'=>$total,'hadir'=>$counts['Hadir'],'izin'=>$counts['Izin'],'sakit'=>$counts['Sakit'],'alpa'=>$counts['Alpa'],'missing'=>$counts['Belum ada data']];
            }
            return self::projection($summary,$rows,$warnings);
        }
        $weekday=['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][Carbon::createFromFormat('!Y-m-d',$date,'Asia/Jakarta')->isoWeekday()-1];
        $summary=['Pola jadwal'=>0,'Lengkap'=>0,'Sebagian'=>0,'Belum tercatat'=>0,'Belum selesai'=>0,'Tanpa siswa'=>0];$rows=[];
        foreach($scoped as $s){if(strcasecmp($s->hari,$weekday)!==0)continue;$summary['Pola jadwal']++;
            $total=count($roster[$s->kelas_id]??[]);$saved=count($valid[$s->id]??[]);$end=(string)$s->jam_selesai;
            $state=$total===0?'Tanpa siswa':($date>$now->toDateString()||($date===$now->toDateString()&&$now->format('H:i:s')<$end)?'Belum selesai':($saved===0?'Belum tercatat':($saved===$total?'Lengkap':'Sebagian')));
            $summary[$state]++;$rows[]=['id'=>(int)$s->id,'class_id'=>(int)$s->kelas_id,'class_name'=>$s->class_name,'subject_name'=>$s->subject_name,'teacher_name'=>$s->teacher_name,'time'=>substr($s->jam_mulai,0,5).'–'.substr($end,0,5),'total'=>$total,'recorded'=>$saved,'state'=>$state];
        }
        return self::projection($summary,$rows,$warnings);
    }
}
