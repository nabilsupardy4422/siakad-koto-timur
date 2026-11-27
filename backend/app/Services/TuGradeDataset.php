<?php
namespace App\Services;

use App\Services\TuAcademicReadService as Read;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class TuGradeDataset
{
    private static function projection(array $summary,array $rows,array $warnings): array
    {
        return ['summary'=>$summary,'rows'=>array_values($rows),'warnings'=>array_values(array_unique($warnings))];
    }
    public static function build(array $c): array
    {
        extract($c);
        abort_unless(isset($d['class_id']),422,'Pilih satu rombel untuk memantau penilaian.');
        $allMembers=Read::members($selected->pluck('id'));
        $warnings=[];
        if($allMembers->contains(fn($m)=>!Read::validInterval($m)))$warnings[]='Terdapat interval anggota kelas tidak valid; tidak digunakan untuk roster.';
        $allMembers=$allMembers->filter(fn($m)=>Read::validInterval($m));
        $ever=$allMembers->filter(fn($m)=>$m->tanggal_mulai<=$date)->keyBy('siswa_id');
        $effective=$allMembers->filter(fn($m)=>$m->tanggal_mulai<=$date&&($m->tanggal_selesai===null||$m->tanggal_selesai>=$date));
        if($effective->groupBy('siswa_id')->contains(fn($g)=>$g->count()>1))$warnings[]='Terdapat keanggotaan efektif ganda di rombel ini; siswa dihitung satu kali.';
        $roster=$effective->keyBy('siswa_id');
        if($active && $roster->isNotEmpty()) {
            $others=Read::members($classes->pluck('id'),$date)->filter(fn($m)=>Read::validInterval($m)&&$roster->has($m->siswa_id));
            if($others->groupBy('siswa_id')->contains(fn($g)=>$g->pluck('kelas_id')->unique()->count()>1))$warnings[]='Ada siswa efektif di lebih dari satu rombel. Periksa integritas keanggotaan.';
        }
        $components=Read::bounded(DB::table('komponen_nilai')->whereIn('jadwal_pelajaran_id',$scoped->pluck('id'))->orderBy('id'),30000);
        $allComponents=$components;
        if(isset($d['component_id'])){$components=$components->where('id',$d['component_id']);abort_if($components->isEmpty(),422,'Komponen tidak sesuai jadwal.');}
        $bySchedule=$scoped->keyBy('id');$componentMap=$components->keyBy('id');
        $records=Read::bounded(DB::table('nilai as n')->join('siswa as s','s.id','=','n.siswa_id')->whereIn('n.komponen_nilai_id',$components->pluck('id'))->select('n.*','s.nama_lengkap','s.nisn','s.nipd','s.nis')->orderBy('n.id'),30000);
        $duplicates=$records->groupBy(fn($r)=>$r->komponen_nilai_id.':'.$r->siswa_id)->filter(fn($g)=>$g->count()>1);
        $valid=[];$detail=[];$anomalies=0;$invalid=[];
        foreach($records as $r){$comp=$componentMap[$r->komponen_nilai_id];$s=$bySchedule[$comp->jadwal_pelajaran_id];$key=$comp->id.':'.$r->siswa_id;
            $ok=$ever->has($r->siswa_id)&&is_numeric($r->nilai)&&(float)$r->nilai>=0&&(float)$r->nilai<=100&&!isset($duplicates[$key]);
            if($ok)$valid[$comp->id][$r->siswa_id]=$r;else {$anomalies++;$invalid[$comp->id][$r->siswa_id]=true;}
            if($d['view']==='detail')$detail[]=['id'=>'grade-'.$r->id,...Read::student($r),'class_id'=>(int)$s->kelas_id,'class_name'=>$s->class_name,'subject_name'=>$s->subject_name,'teacher_name'=>$s->teacher_name,'schedule_id'=>(int)$s->id,'component_id'=>(int)$comp->id,'component'=>$comp->nama,'weight'=>(string)$comp->bobot,'score'=>$ok?(float)$r->nilai:null,'note'=>$r->catatan,'state'=>$ok?($active&&$roster->has($r->siswa_id)?'Tercatat':'Riwayat tercatat'):'Anomali'];
        }
        if($anomalies)$warnings[]='Ada nilai di luar riwayat anggota, duplikat, atau di luar rentang 0–100; tidak dihitung sebagai nilai valid.';
        $summary=['Siswa roster efektif'=>$active?$roster->count():null,'Jadwal'=>$scoped->count(),'Komponen'=>$components->count(),'Slot saat ini'=>$active?0:null,'Terisi saat ini'=>$active?0:null,'Belum dinilai'=>$active?0:null,'Slot anomali'=>$active?0:null,'Catatan valid'=>0,'Anomali'=>$anomalies];
        $rows=[];
        foreach($scoped as $s){$group=$components->where('jadwal_pelajaran_id',$s->id);if(isset($d['component_id'])&&$group->isEmpty())continue;
            $slots=$active?$roster->count()*$group->count():null;$filled=$active?0:null;$invalidSlots=$active?0:null;$recorded=0;
            foreach($group as $comp){$scores=$valid[$comp->id]??[];$recorded+=count($scores);$current=$active?count(array_intersect_key($scores,$roster->all())):null;if($active){$filled+=$current;$invalidSlots+=count(array_intersect_key($invalid[$comp->id]??[],$roster->all()));}
                if($d['view']==='components')$rows[]=['id'=>(int)$comp->id,'class_id'=>(int)$s->kelas_id,'class_name'=>$s->class_name,'subject_name'=>$s->subject_name,'teacher_name'=>$s->teacher_name,'schedule_id'=>(int)$s->id,'component'=>$comp->nama,'weight'=>(string)$comp->bobot,'total_weight'=>(string)$allComponents->where('jadwal_pelajaran_id',$s->id)->sum('bobot'),'recorded'=>count($scores)];
            }
            $summary['Catatan valid']+=$recorded;if($active){$summary['Slot saat ini']+=$slots;$summary['Terisi saat ini']+=$filled;$summary['Belum dinilai']+=$slots-$filled-$invalidSlots;$summary['Slot anomali']+=$invalidSlots;}
            if($d['view']==='coverage')$rows[]=['id'=>(int)$s->id,'class_id'=>(int)$s->kelas_id,'class_name'=>$s->class_name,'subject_name'=>$s->subject_name,'teacher_name'=>$s->teacher_name,'time'=>$s->hari.' '.substr($s->jam_mulai,0,5).'–'.substr($s->jam_selesai,0,5),'components'=>$group->count(),'slots'=>$slots,'filled'=>$filled,'missing'=>$active?$slots-$filled-$invalidSlots:null,'invalid_slots'=>$invalidSlots,'recorded'=>$recorded,'state'=>$group->isEmpty()?'Belum ada komponen':(!$active?'Riwayat':($slots===0?'Tanpa siswa':($filled===$slots?'Lengkap':'Belum lengkap')))];
        }
        if($d['view']==='detail'){
            if($active){abort_if($summary['Slot saat ini']>30000,422,'Pilih komponen atau mata pelajaran untuk mempersempit detail nilai.');
                $existing=$records->groupBy(fn($r)=>$r->komponen_nilai_id.':'.$r->siswa_id);
                foreach($components as $comp){$s=$bySchedule[$comp->jadwal_pelajaran_id];foreach($roster as $id=>$m){if($existing->has($comp->id.':'.$id))continue;
                    $detail[]=['id'=>'missing-'.$comp->id.'-'.$id,...Read::student($m),'class_id'=>(int)$s->kelas_id,'class_name'=>$s->class_name,'subject_name'=>$s->subject_name,'teacher_name'=>$s->teacher_name,'schedule_id'=>(int)$s->id,'component_id'=>(int)$comp->id,'component'=>$comp->nama,'weight'=>(string)$comp->bobot,'score'=>null,'note'=>null,'state'=>'Belum dinilai'];
                }}
            }
            $rows=array_values(array_filter($detail,fn($r)=>Read::matches($r,trim($d['search']??''))));
            usort($rows,fn($a,$b)=>strnatcasecmp($a['student_name'],$b['student_name'])?:($a['component_id']<=>$b['component_id'])?:strcmp($a['id'],$b['id']));
            $summary=['Baris ditemukan'=>count($rows),'Tercatat'=>count(array_filter($rows,fn($r)=>$r['score']!==null)),'Belum dinilai'=>count(array_filter($rows,fn($r)=>$r['state']==='Belum dinilai')),'Anomali'=>count(array_filter($rows,fn($r)=>$r['state']==='Anomali'))];
        }
        return self::projection($summary,$rows,$warnings);
    }
}
