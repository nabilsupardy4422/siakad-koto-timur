<?php
namespace App\Services\Reporting;

use App\Models\User;
use App\Services\TuAcademicReadService as Read;
use App\Services\TuAttendanceDataset;
use App\Services\TuGradeDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/** TU-only dataset, shared with monitoring rather than reconstructing browser pages. */
final class TuReportDataset
{
    public const FILTERS = [
        'students'=>['academic_year_id','class_id','subject_id','schedule_id','search'],
        'teachers'=>['search','status'], 'classes'=>['academic_year_id'],
        'schedules'=>['academic_year_id','class_id','subject_id','teacher_id','schedule_id'],
        'attendance'=>['academic_year_id','class_id','subject_id','schedule_id','student_id','date_from','date_to'],
        'grades'=>['academic_year_id','class_id','subject_id','schedule_id','student_id'],
        'semester-recap'=>['academic_year_id','class_id','student_id'],
        'attendance-daily'=>['academic_year_id','class_id','level','date'],
        'attendance-monthly'=>['academic_year_id','class_id','level','month'],
        'attendance-coverage'=>['academic_year_id','class_id','level','subject_id','teacher_id','schedule_id','date'],
        'grade-coverage'=>['academic_year_id','class_id','level','subject_id','teacher_id','schedule_id'],
        'grade-components'=>['academic_year_id','class_id','level','subject_id','teacher_id','schedule_id'],
    ];
    public static function build(string $type,User $user,array $f,ReportService $legacy): array
    {
        abort_unless($user->role?->code==='TU',403);
        $f=ReportAccess::normalize($user,$type,$f);
        $new=array_key_exists($type,self::SPECS);
        $c=null;
        if($type!=='teachers'){
            $ctx=array_intersect_key($f,array_flip(['academic_year_id','class_id','level','subject_id','teacher_id','schedule_id']));
            $ctx['view']=match($type){'attendance-daily'=>'daily','grade-components'=>'components',default=>'coverage'};
            $attendance=str_starts_with($type,'attendance');
            if($attendance)$ctx['date']=$f['date']??(isset($f['month'])?$f['month'].'-01':($f['date_from']??$f['date_to']??null));
            // Legacy report date ranges are checked against the selected calendar period separately.
            if(!$new){$ctx['view']='catalog';}
            $c=Read::contextFor($user,$ctx,$attendance);
            foreach(['date_from','date_to'] as $key)if(isset($f[$key]))abort_if((int)substr($f[$key],0,4)<(int)$c['year']->tahun_mulai||(int)substr($f[$key],0,4)>(int)$c['year']->tahun_selesai,422,'Tanggal di luar periode kalender.');
            if(isset($f['student_id'])){
                $classIds=isset($f['subject_id'])||isset($f['schedule_id'])?$c['scoped']->pluck('kelas_id'):$c['selected']->pluck('id');
                abort_unless(Read::members($classIds)->contains(fn($m)=>(int)$m->siswa_id===(int)$f['student_id']&&Read::validInterval($m)&&$m->tanggal_mulai<=$c['now']->toDateString()),422,'Siswa tidak sesuai konteks akademik.');
            }
        }
        if(!$new){if($type==='students') Read::members($c['selected']->pluck('id'));$report=$legacy->build($type,$user,$f,30000);abort_if($report['rows']->count()>30000,422,'Persempit filter laporan.');return $report;}
        $p=match($type){'attendance-monthly'=>self::monthly($c,$f['month']), 'grade-components','grade-coverage'=>TuGradeDataset::build($c), default=>TuAttendanceDataset::build($c)};
        [$title,$note,$columns]=self::SPECS[$type];
        $rows=collect($p['rows'])->map(function($r)use($columns){$out=[];foreach($columns as $k=>$label){$value=$r[$k]??null;$out[$k]=in_array($k,['weight','total_weight'],true)&&is_numeric($value)?(float)$value:$value;}return $out;});
        if($type==='attendance-coverage'){$s=$p['summary'];$den=$s['Lengkap']+$s['Sebagian']+$s['Belum tercatat'];$p['summary']['Kelengkapan jadwal selesai (%)']=$den?round(100*$s['Lengkap']/$den,2):null;}
        if($type==='grade-coverage'){$s=$p['summary'];$p['summary']['Kelengkapan slot (%)']=$s['Slot saat ini']>0?round(100*$s['Terisi saat ini']/$s['Slot saat ini'],2):null;}
        if(str_starts_with($type,'grade-'))$note.=' Acuan WIB: '.$c['date'].'.';
        if($type==='attendance-monthly')$note.=' Rentang efektif: '.$f['month'].'-01 s.d. '.min($c['now']->toDateString(),Carbon::parse($f['month'].'-01','Asia/Jakarta')->endOfMonth()->toDateString()).'.';
        return ['title'=>$title,'headings'=>array_values($columns),'rows'=>$rows,'effective_filters'=>$f,'note'=>$note,'summary'=>$p['summary'],'warnings'=>$p['warnings']];
    }
    private static function monthly(array $c,string $month): array
    {
        $from=$month.'-01';$to=min($c['now']->toDateString(),Carbon::parse($from,'Asia/Jakarta')->endOfMonth()->toDateString());
        abort_if($from>$to,422,'Bulan belum dimulai.');
        $all=Read::members($c['selected']->pluck('id'));$warnings=[];
        if($all->contains(fn($m)=>!Read::validInterval($m)))$warnings[]='Interval anggota tidak valid dikecualikan.';
        $members=$all->filter(fn($m)=>Read::validInterval($m)&&$m->tanggal_mulai<=$to&&($m->tanggal_selesai===null||$m->tanggal_selesai>=$from))->groupBy(fn($m)=>$m->kelas_id.':'.$m->siswa_id);
        $schedules=$c['scoped']->keyBy('id');$classes=$c['selected']->keyBy('id');
        $records=Read::bounded(DB::table('presensi')->whereIn('jadwal_pelajaran_id',$schedules->keys())->whereDate('tanggal','>=',$from)->whereDate('tanggal','<=',$to)->orderBy('id'),30000);
        $duplicates=$records->groupBy(fn($r)=>$r->jadwal_pelajaran_id.':'.$r->siswa_id.':'.substr($r->tanggal,0,10))->filter(fn($g)=>$g->count()>1);
        $counts=[];$bad=0;$valid=0;
        foreach($records as $r){$s=$schedules[$r->jadwal_pelajaran_id];$date=Read::calendarDate($r->tanggal);$key=$s->kelas_id.':'.$r->siswa_id;$status=strtoupper((string)$r->status);
            if(!$date||!in_array($status,['HADIR','IZIN','SAKIT','ALPA'],true)||$duplicates->has($r->jadwal_pelajaran_id.':'.$r->siswa_id.':'.$date)||!($members[$key]??collect())->contains(fn($m)=>$m->tanggal_mulai<=$date&&($m->tanggal_selesai===null||$m->tanggal_selesai>=$date))){$bad++;continue;}
            $counts[$key][$status]=($counts[$key][$status]??0)+1;$valid++;
        }
        $rows=[];foreach($members as $key=>$group){$m=$group->first();$class=$classes[$m->kelas_id];$n=$counts[$key]??[];$total=array_sum($n);$rows[]=[...Read::student($m),'class_name'=>$class->nama,'level'=>(int)$class->tingkat,'month'=>$month,'hadir'=>$n['HADIR']??0,'izin'=>$n['IZIN']??0,'sakit'=>$n['SAKIT']??0,'alpa'=>$n['ALPA']??0,'total'=>$total,'percentage'=>$total?round(100*($n['HADIR']??0)/$total,2):null,'class_id'=>(int)$class->id];}
        usort($rows,fn($a,$b)=>($a['level']<=>$b['level'])?:strcmp($a['class_name'],$b['class_name'])?:($a['class_id']<=>$b['class_id'])?:strnatcasecmp($a['student_name'],$b['student_name'])?:($a['student_id']<=>$b['student_id']));
        if($bad)$warnings[]='Catatan anomali (status, duplikat atau roster) dikecualikan.';
        return ['rows'=>$rows,'warnings'=>$warnings,'summary'=>['Siswa per rombel'=>count($rows),'Catatan valid'=>$valid,'Anomali'=>$bad]];
    }
    private const SPECS=[
        'attendance-daily'=>['Rekap Presensi Harian','Satu rombel per tanggal. Belum ada data bukan Alpa; prioritas Alpa, Sakit, Izin, Hadir. Total bukan siswa unik sekolah.',['class_id'=>'ID Rombel','class_name'=>'Rombel','level'=>'Tingkat','total'=>'Total Siswa','hadir'=>'Hadir','izin'=>'Izin','sakit'=>'Sakit','alpa'=>'Alpa','missing'=>'Belum Ada Data']],
        'attendance-monthly'=>['Rekap Presensi Bulanan','Satu siswa per rombel per bulan; hanya catatan pertemuan valid. Bukan hari sekolah. Persentase kosong bila tanpa catatan.',['student_name'=>'Nama Siswa','nisn'=>'NISN','nipd'=>'NIPD','nis'=>'NIS','class_id'=>'ID Rombel','class_name'=>'Rombel','level'=>'Tingkat','month'=>'Bulan','hadir'=>'Hadir','izin'=>'Izin','sakit'=>'Sakit','alpa'=>'Alpa','total'=>'Total Catatan','percentage'=>'Hadir dari Catatan (%)']],
        'attendance-coverage'=>['Kelengkapan Input Presensi','Pola jadwal, bukan bukti KBM atau disiplin. Denominator hanya jadwal selesai dengan roster nonkosong.',['id'=>'ID Jadwal','class_name'=>'Rombel','subject_name'=>'Mata Pelajaran','teacher_name'=>'Guru','time'=>'Jam','total'=>'Target Siswa','recorded'=>'Catatan Valid','state'=>'Status']],
        'grade-coverage'=>['Kelengkapan Penilaian','Slot = roster efektif x komponen per jadwal; histori hanya catatan tersimpan. Tidak ada tanggal nilai pada skema.',['id'=>'ID Jadwal','class_name'=>'Rombel','subject_name'=>'Mata Pelajaran','teacher_name'=>'Guru','time'=>'Hari/Jam','components'=>'Komponen','slots'=>'Slot','filled'=>'Terisi','missing'=>'Belum Dinilai','invalid_slots'=>'Slot Anomali','recorded'=>'Catatan Valid','state'=>'Status']],
        'grade-components'=>['Komponen dan Bobot Penilaian','Bobot asli; tidak dinormalisasi, bukan rumus nilai akhir atau rapor.',['id'=>'ID Komponen','class_name'=>'Rombel','subject_name'=>'Mata Pelajaran','teacher_name'=>'Guru','schedule_id'=>'ID Jadwal','component'=>'Komponen','weight'=>'Bobot','total_weight'=>'Total Bobot Jadwal','recorded'=>'Catatan Valid']],
    ];
}
