<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TahunAkademik;
use App\Services\TuAcademicReadService as Read;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Additive, read-only TU dashboard. Existing /dashboard remains untouched. */
final class TuDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()?->role?->code === 'TU', 403);
        $rules = ['section'=>'required|in:context,distribution,homeroom', 'academic_year_id'=>'required_unless:section,context|integer|min:1', 'date'=>'required_unless:section,context|date_format:Y-m-d'];
        $d = $request->validate($rules);
        abort_if(count(array_diff(array_keys($request->query()), array_keys($rules)))>0,422,'Filter tidak didukung.');
        $today = now('Asia/Jakarta')->toDateString();
        $active = TahunAkademik::where('is_active',true)->orderBy('id')->get();
        $year = $active->count() === 1 ? $active->first() : null;
        $period = fn($y) => ['id'=>(int)$y->id,'tahun_mulai'=>(int)$y->tahun_mulai,'tahun_selesai'=>(int)$y->tahun_selesai,'semester'=>$y->semester,'is_active'=>(bool)$y->is_active];
        if ($d['section']==='context') {
            abort_if(isset($d['academic_year_id']) || isset($d['date']),422,'Konteks tidak menerima filter.');
            $data=['role'=>'TU','date'=>$today,'active_state'=>$year?'active':($active->isEmpty()?'none':'conflict'),'period'=>$year?$period($year):null,
                'master'=>['teachers'=>DB::table('guru')->count(),'students'=>DB::table('siswa')->count(),'subjects'=>DB::table('mapel')->count()],
                'recent'=>TahunAkademik::orderByDesc('id')->limit(5)->get()->map($period)->values()];
        } else {
            abort_unless($year && (int)$year->id===(int)$d['academic_year_id'] && $today===$d['date'],409,'Periode atau tanggal berubah. Muat ulang dashboard.');
            if ($d['section']==='homeroom') {
                // The existing V6 workspace checks assignment pairs; bound work explicitly.
                abort_if(DB::table('kelas')->where('tahun_akademik_id',$year->id)->count()>5000,422,'Rombel melebihi batas dashboard. Buka Manajemen Wali Kelas.');
                abort_if(\App\Models\WaliKelas::where(fn($q)=>$q->where('tahun_akademik_id',$year->id)->orWhereHas('kelas',fn($c)=>$c->where('tahun_akademik_id',$year->id)))->count()>1000,422,'Riwayat penugasan melebihi batas dashboard. Buka Manajemen Wali Kelas.');
                $workspace=app(\App\Services\HomeroomAssignmentService::class)->workspace($year);
                $data=array_intersect_key($workspace,array_flip(['period_id','as_of','active_state','summary']));
                abort_unless($data['active_state']==='active' && $data['as_of']===$today,409,'Konteks berubah. Muat ulang dashboard.');
                return response()->json(['data'=>$data])->header('Cache-Control','private, no-store');
            }
            $classes=Read::bounded(DB::table('kelas')->where('tahun_akademik_id',$year->id)->select('id','nama','tingkat')->orderBy('tingkat')->orderBy('nama')->orderBy('id'),5000);
            $members=Read::members($classes->pluck('id'));
            $valid=$members->filter(fn($m)=>Read::validInterval($m));
            $effective=$valid->filter(fn($m)=>$m->tanggal_mulai<=$today && ($m->tanggal_selesai===null||$m->tanggal_selesai>=$today));
            $byClass=$effective->groupBy('kelas_id');
            $rows=$classes->map(fn($c)=>['id'=>(int)$c->id,'name'=>$c->nama,'level'=>(int)$c->tingkat,'students'=>($byClass->get($c->id)??collect())->pluck('siswa_id')->unique()->count()])->values();
            $groups=$effective->groupBy('siswa_id');
            $data=['academic_year_id'=>(int)$year->id,'date'=>$today,'semester'=>$year->semester,'classes'=>$rows,'unique_students'=>$groups->count(),'memberships'=>$rows->sum('students'),
                'invalid_intervals'=>$members->count()-$valid->count(),'duplicate_effective'=>$groups->filter(fn($g)=>$g->count()>1)->count(),'cross_class'=>$groups->filter(fn($g)=>$g->pluck('kelas_id')->unique()->count()>1)->count()];
            $current=TahunAkademik::where('is_active',true)->pluck('id');
            abort_unless($current->count()===1 && (int)$current->first()===(int)$year->id && now('Asia/Jakarta')->toDateString()===$today,409,'Konteks berubah. Muat ulang dashboard.');
        }
        return response()->json(['data'=>$data])->header('Cache-Control','private, no-store');
    }
}
