<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TahunAkademik;
use App\Services\TuAcademicReadService as Read;
use App\Services\TuAttendanceDataset;
use App\Services\PrincipalAcademicIntegrity as Integrity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PrincipalDashboardWorkspaceController extends Controller
{
    private function response(array $data, array $meta=[])
    {
        return response()->json(['data'=>$data,'meta'=>$meta])->header('Cache-Control','private, no-store');
    }
    private function guard(Request $r, array $rules): array
    {
        abort_unless($r->user()?->role?->code==='KEPALA_SEKOLAH',403);
        abort_if(count(array_diff(array_keys($r->query()),array_keys($rules)))>0,422,'Filter tidak didukung.');
        return $r->validate($rules);
    }
    public function dashboard(Request $r)
    {
        $this->guard($r,[]);$today=now('Asia/Jakarta')->toDateString();
        $active=TahunAkademik::where('is_active',true)->get();$year=$active->count()===1?$active->first():null;
        $summary=['total_guru'=>DB::table('guru')->count(),'total_mapel'=>DB::table('mapel')->count(),'total_siswa'=>DB::table('siswa')->count(),'total_kelas'=>null,'total_jadwal'=>null,'effective_students'=>null,'active_academic_year'=>null];$rows=[];$coverage=null;$warnings=[];
        if($year){
            $c=Read::principalContextFor($r->user(),['academic_year_id'=>$year->id,'view'=>'catalog'],false);
            $all=Read::members($c['classes']->pluck('id'));$members=$all->filter(fn($m)=>Read::validInterval($m)&&$m->tanggal_mulai<=$today&&($m->tanggal_selesai===null||$m->tanggal_selesai>=$today));$byClass=$members->groupBy('kelas_id');
            $summary['total_kelas']=$c['classes']->count();$summary['total_jadwal']=$c['schedules']->count();$summary['effective_students']=$members->pluck('siswa_id')->unique()->count();
            $summary['active_academic_year']=['id'=>(int)$year->id,'tahun_mulai'=>(int)$year->tahun_mulai,'tahun_selesai'=>(int)$year->tahun_selesai,'semester'=>$year->semester,'is_active'=>true];
            foreach($c['classes'] as $class)$rows[]=['class_id'=>(int)$class->id,'class_name'=>$class->nama,'tingkat'=>(int)$class->tingkat,'student_count'=>($byClass[$class->id]??collect())->pluck('siswa_id')->unique()->count()];
            if($all->contains(fn($m)=>!Read::validInterval($m)))$warnings[]='Interval anggota tidak valid dikecualikan.';
            if($members->groupBy('siswa_id')->contains(fn($g)=>$g->count()>1))$warnings[]='Keanggotaan efektif ganda; jumlah per rombel bukan siswa unik sekolah.';
            // Dashboard context remains available if date falls outside the active calendar year.
            if((int)substr($today,0,4)>=(int)$year->tahun_mulai&&(int)substr($today,0,4)<=(int)$year->tahun_selesai){
                $c['d']['view']='coverage';$c['date']=$today;$c['actual_only']=true;
                $p=TuAttendanceDataset::build($c);$coverage=$p['summary'];$warnings=array_merge($warnings,$p['warnings']);
            }
            $ids=TahunAkademik::where('is_active',true)->pluck('id');abort_unless($ids->count()===1&&(int)$ids->first()===(int)$year->id&&now('Asia/Jakarta')->toDateString()===$today,409,'Konteks berubah. Muat ulang.');
        }
        return $this->response(['role'=>'KEPALA_SEKOLAH','date'=>$today,'active_state'=>$year?'active':($active->isEmpty()?'none':'conflict'),'summary'=>$summary,'charts'=>['students_by_class'=>$rows],'coverage'=>$coverage,'warnings'=>array_values(array_unique($warnings))]);
    }
    public function daily(Request $r)
    {
        $d=$this->guard($r,['academic_year_id'=>'required|integer|min:1','date'=>'required|date_format:Y-m-d','semester'=>'required|in:GANJIL,GENAP']);
        abort_if($d['date']>now('Asia/Jakarta')->toDateString(),422,'Rekap aktual tidak menerima tanggal masa depan.');
        $c=Read::principalContextFor($r->user(),$d+['view'=>'daily'],true);abort_unless($c['year']->semester===$d['semester'],422,'Semester berbeda.');$c['actual_only']=true;
        $p=TuAttendanceDataset::build($c);$rows=array_map(fn($v)=>['class_id'=>$v['class_id'],'nama_kelas'=>$v['class_name'],'tingkat'=>$v['level'],'total_siswa'=>$v['total'],'hadir'=>$v['hadir'],'izin'=>$v['izin'],'sakit'=>$v['sakit'],'alpa'=>$v['alpa'],'belum_ada_data'=>$v['missing']],$p['rows']);
        return $this->response($rows,['academic_year_id'=>(int)$c['year']->id,'semester'=>$c['year']->semester,'date'=>$c['date'],'total_classes'=>count($rows),'warnings'=>$p['warnings']]);
    }
    public function attendance(Request $r)
    {
        $d=$this->guard($r,['academic_year_id'=>'required|integer|min:1','date_from'=>'required|date_format:Y-m-d','date_to'=>'required|date_format:Y-m-d|after_or_equal:date_from']);$year=TahunAkademik::findOrFail($d['academic_year_id']);$today=now('Asia/Jakarta')->toDateString();
        $q=DB::table('presensi as p')->join('jadwal_pelajaran as j','j.id','=','p.jadwal_pelajaran_id')->join('kelas as k','k.id','=','j.kelas_id')->where('j.tahun_akademik_id',$year->id)->whereColumn('k.tahun_akademik_id','j.tahun_akademik_id')->whereDate('p.tanggal','>=',$d['date_from'])->whereDate('p.tanggal','<=',$d['date_to']);Integrity::attendance($q,$today);
        $counts=['hadir'=>0,'izin'=>0,'sakit'=>0,'alpa'=>0];foreach($q->selectRaw('LOWER(p.status) as status, COUNT(*) as n')->groupByRaw('LOWER(p.status)')->get() as $row)$counts[$row->status]=(int)$row->n;
        return $this->response(['total'=>array_sum($counts),...$counts],['academic_year_id'=>(int)$year->id,'date_from'=>$d['date_from'],'date_to'=>$d['date_to'],'date'=>$today]);
    }
    public function grades(Request $r)
    {
        $d=$this->guard($r,['academic_year_id'=>'required|integer|min:1']);$year=TahunAkademik::findOrFail($d['academic_year_id']);$today=now('Asia/Jakarta')->toDateString();
        $q=DB::table('nilai as n')->join('komponen_nilai as c','c.id','=','n.komponen_nilai_id')->join('jadwal_pelajaran as j','j.id','=','c.jadwal_pelajaran_id')->join('kelas as k','k.id','=','j.kelas_id')->where('j.tahun_akademik_id',$year->id)->whereColumn('k.tahun_akademik_id','j.tahun_akademik_id');Integrity::grades($q,$today);
        $row=$q->selectRaw('COUNT(*) as total, AVG(n.nilai) as rata_rata, MIN(n.nilai) as terendah, MAX(n.nilai) as tertinggi')->first();
        return $this->response(['total'=>(int)$row->total,'rata_rata'=>$row->rata_rata===null?null:(float)$row->rata_rata,'terendah'=>$row->terendah===null?null:(float)$row->terendah,'tertinggi'=>$row->tertinggi===null?null:(float)$row->tertinggi],['academic_year_id'=>(int)$year->id,'date'=>$today]);
    }
}
