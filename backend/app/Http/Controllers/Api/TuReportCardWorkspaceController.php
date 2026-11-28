<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TahunAkademik;
use App\Services\TuAcademicReadService as Read;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TuReportCardWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->role?->code==='TU',403);
        $rules=['academic_year_id'=>'required|integer|min:1','view'=>'required|in:catalog,coverage,documents,candidates','type'=>'required_unless:view,catalog|in:MID_SEMESTER,AKHIR_SEMESTER','class_id'=>'nullable|integer|min:1','level'=>'nullable|in:10,11,12','search'=>'nullable|string|max:100','status'=>'nullable|in:Tersedia,Belum diunggah,Dicabut,File hilang','page'=>'nullable|integer|min:1|max:100000'];
        $d=$request->validate($rules);
        abort_if(count(array_diff(array_keys($request->query()),array_keys($rules)))>0,422,'Filter tidak didukung.');
        $year=TahunAkademik::findOrFail($d['academic_year_id']);$today=now('Asia/Jakarta')->toDateString();
        $classes=Read::bounded(DB::table('kelas')->where('tahun_akademik_id',$year->id)->orderBy('tingkat')->orderBy('nama')->orderBy('id'),5000);
        $active=$year->is_active && TahunAkademik::where('is_active',true)->count()===1;
        $meta=['academic_year_id'=>(int)$year->id,'semester'=>$year->semester,'active'=>$active,'date'=>$today,'view'=>$d['view'],'filters'=>$d];
        if($d['view']==='catalog'){
            abort_if(count(array_diff(array_keys($d),['academic_year_id','view']))>0,422,'Katalog hanya menerima periode.');
            return response()->json(['data'=>['classes'=>$classes],'meta'=>$meta])->header('Cache-Control','private, no-store');
        }
        $selected=$classes->filter(fn($c)=>(!isset($d['class_id'])||(int)$d['class_id']===(int)$c->id)&&(!isset($d['level'])||(int)$d['level']===(int)$c->tingkat));
        abort_if(isset($d['class_id'])&&$selected->isEmpty(),422,'Rombel tidak sesuai periode atau tingkat.');
        $names=$classes->keyBy('id');$members=Read::members($selected->pluck('id'));
        $valid=$members->filter(fn($m)=>Read::validInterval($m)&&$m->tanggal_mulai<=$today);
        $effective=$valid->filter(fn($m)=>$m->tanggal_selesai===null||$m->tanggal_selesai>=$today);
        $current=$effective->groupBy('siswa_id');$target=($active?$effective:$valid)->groupBy('siswa_id');$ever=$valid->groupBy('siswa_id');
        $anomalies=$members->filter(fn($m)=>!Read::validInterval($m))->count();
        $overlap=$effective->groupBy('siswa_id')->filter(fn($g)=>$g->count()>1)->count();
        $docs=Read::bounded(DB::table('rapor as r')->join('siswa as s','s.id','=','r.siswa_id')->join('users as u','u.id','=','r.uploaded_by')->where('r.tahun_akademik_id',$year->id)->where('r.jenis_rapor',$d['type'])->select('r.*','s.nama_lengkap','s.nis','s.nipd','s.nisn','u.name as uploader')->orderBy('r.id'),30000)->keyBy('siswa_id');
        $scopedDocs=isset($d['class_id'])||isset($d['level'])?$docs->filter(fn($r)=>$ever->has($r->siswa_id)):$docs;
        $status=[];
        foreach($scopedDocs as $r){abort_unless(in_array($r->distribution_status,['active','revoked'],true),500,'Status distribusi tidak dikenal.');$exists=\App\Services\ReportCardDistribution::available($r->file_path);$status[$r->siswa_id]=[$r->distribution_status==='revoked'?'Dicabut':($exists?'Tersedia':'File hilang'),$exists];}
        $row=function($student,$doc,$membership)use($names,$status,$target,$current,$ever){
            $id=(int)$student->siswa_id;$state=$status[$id]??['Belum diunggah',null];
            return Read::student($student)+['id'=>$id,'class_names'=>$membership?->map(fn($m)=>$names[$m->kelas_id]->nama.' · '.$names[$m->kelas_id]->tingkat)->unique()->values()->implode(', ')??'—','member_state'=>$current->has($id)?'Efektif':($ever->has($id)?'Historis':'Di luar roster'),'target'=>$target->has($id),'status'=>$state[0],'file_exists'=>$state[1],'document_id'=>$doc?->id,'version'=>$doc?(int)$doc->document_version:null,'file_name'=>$doc?->file_name,'file_size'=>$doc?(int)$doc->file_size:null,'uploader'=>$doc?->uploader,'uploaded_at'=>$doc?->uploaded_at];
        };
        $coverage=$target->map(fn($g,$id)=>$row($g->first(),$scopedDocs->get($id),$g))->values();
        $summary=['Target siswa'=>$coverage->count(),'Tersedia'=>$coverage->where('status','Tersedia')->count(),'Belum diunggah'=>$coverage->where('status','Belum diunggah')->count(),'Dicabut'=>$coverage->where('status','Dicabut')->count(),'File hilang'=>$coverage->where('status','File hilang')->count(),'Dokumen di luar target'=>$scopedDocs->filter(fn($r)=>!$target->has($r->siswa_id))->count()];
        $rows=match($d['view']){
            'coverage'=>$coverage,
            'documents'=>$scopedDocs->map(fn($r)=>$row($r,$r,$ever->get($r->siswa_id)))->values(),
            'candidates'=>$ever->map(fn($g,$id)=>$row($g->first(),$scopedDocs->get($id),$g))->values(),
        };
        $rows=$rows->filter(fn($r)=>(!isset($d['status'])||$r['status']===$d['status'])&&Read::matches($r,$d['search']??''))->sort(fn($a,$b)=>strnatcasecmp($a['student_name'],$b['student_name'])?:$a['id']<=>$b['id'])->values();
        $page=(int)($d['page']??1);$total=$rows->count();$last=max(1,(int)ceil($total/20));abort_if($page>$last,422,'Halaman tidak tersedia.');
        return response()->json(['data'=>['summary'=>$summary,'rows'=>$rows->forPage($page,20)->values(),'warnings'=>array_values(array_filter([$anomalies?"$anomalies interval keanggotaan tidak valid.":null,$overlap?"$overlap siswa memiliki keanggotaan efektif ganda dalam cakupan.":null]))],'meta'=>$meta+['page'=>$page,'last_page'=>$last,'total'=>$total,'per_page'=>20]])->header('Cache-Control','private, no-store');
    }
}
