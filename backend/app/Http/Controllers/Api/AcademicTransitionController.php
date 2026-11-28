<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{AcademicMembershipEvent,AnggotaKelas,Kelas,Siswa,TahunAkademik};
use App\Services\{AcademicTransitionService,ClassMembershipService};
use App\Support\{AcademicTransitionRules,ApiResponse};
use Illuminate\Http\Request;

final class AcademicTransitionController extends Controller {
    private function authorized(Request $request): void { abort_unless($request->user()?->role?->code==='TU',403); }
    private function input(Request $request,bool $commit): array {
        $this->authorized($request);
        return $request->validate([
            'source_period_id'=>'required|integer|min:1','target_period_id'=>'required|integer|min:1','effective_on'=>'required|date_format:Y-m-d',
            'items'=>'required|array|min:1|max:100','items.*'=>'required|array:membership_id,action,destination_class_id',
            'items.*.membership_id'=>'required|integer|min:1|distinct','items.*.action'=>'required|in:move,continue,promote,retain,graduate,withdraw',
            'items.*.destination_class_id'=>'nullable|integer|min:1','preview_token'=>$commit?'required|string|max:100':'nullable',
        ]);
    }
    public function preview(Request $request,AcademicTransitionService $service,ClassMembershipService $signer) {
        $d=$this->input($request,false);$s=$service->snapshot($d);
        return ApiResponse::success(['rows'=>$s['rows'],'ends_on'=>$s['end'],'preview_token'=>$signer->issue($s,(int)$request->user()->id,'transition')]);
    }
    public function commit(Request $request,AcademicTransitionService $service,ClassMembershipService $signer) {
        $n=$service->commit($this->input($request,true),(int)$request->user()->id,$signer);
        return ApiResponse::message('Keputusan akademik tersimpan. Periode aktif dan akun tidak berubah.',['total_processed'=>$n],201);
    }
    public function history(Request $request,Siswa $student) {
        $this->authorized($request);
        $members=AnggotaKelas::where('siswa_id',$student->id)->with('kelas.tahunAkademik')->orderBy('tanggal_mulai')->orderBy('id')->get();
        $events=AcademicMembershipEvent::where('siswa_id',$student->id)->orderBy('effective_on')->orderBy('id')->get();
        return ApiResponse::success(['student'=>['id'=>$student->id,'nama_lengkap'=>$student->nama_lengkap,'nisn'=>$student->nisn,'nipd'=>$student->nipd],
            'memberships'=>$members->map(fn($m)=>['id'=>$m->id,'nama'=>$m->kelas?->nama,'tingkat'=>$m->kelas?->tingkat,'period'=>$m->kelas?->tahunAkademik?->only(['id','tahun_mulai','tahun_selesai','semester']),'tanggal_mulai'=>$m->tanggal_mulai?->toDateString(),'tanggal_selesai'=>$m->tanggal_selesai?->toDateString()]),
            'events'=>$events->map(fn($e)=>$e->only(['id','source_membership_id','destination_membership_id','action','effective_on'])),
            'academic_status'=>$events->whereIn('action',['graduate','withdraw'])->last()?->action??null]);
    }
    public function directory(Request $request) {
        $this->authorized($request);$d=$request->validate(['academic_year_id'=>'required|integer|min:1']);
        $period=TahunAkademik::findOrFail($d['academic_year_id']);$today=today('Asia/Jakarta')->toDateString();
        $classes=Kelas::where('tahun_akademik_id',$period->id)->orderBy('tingkat')->orderBy('nama')->get();
        $members=AnggotaKelas::whereIn('kelas_id',$classes->pluck('id'))->orderBy('id')->get();
        $bad=[];$effective=[];$counts=[];
        foreach($members->groupBy('siswa_id') as $id=>$history){
            foreach($history as $i=>$m){
                $start=$m->tanggal_mulai?->toDateString();$end=$m->tanggal_selesai?->toDateString();
                if(!$start||($end&&$end<$start)) $bad[$m->id]=true;
                elseif($start<=$today&&(!$end||$end>=$today)){ $effective[$id]=true;$counts[$m->kelas_id][$id]=true; }
                foreach($history->slice($i+1) as $other){if(AcademicTransitionRules::overlap($start,$end,$other->tanggal_mulai?->toDateString(),$other->tanggal_selesai?->toDateString())){$bad[$m->id]=true;$bad[$other->id]=true;}}
            }
        }
        // Count identities, not accounts. Terminal decisions exclude students from available placement totals.
        $terminal=AcademicMembershipEvent::whereIn('action',['graduate','withdraw'])->whereDate('effective_on','<=',$today)->distinct()->pluck('siswa_id')->all();
        $eligible=Siswa::whereNotIn('id',$terminal)->count();$placed=count(array_diff(array_keys($effective),$terminal));
        return ApiResponse::success(['as_of'=>$today,'total_students'=>Siswa::count(),'eligible_students'=>$eligible,'placed_students'=>$placed,'unplaced_students'=>max(0,$eligible-$placed),'conflict_records'=>count($bad),
            'classes'=>$classes->map(fn($c)=>['id'=>$c->id,'nama'=>$c->nama,'tingkat'=>$c->tingkat,'tahun_akademik_id'=>$c->tahun_akademik_id,'effective_count'=>count($counts[$c->id]??[]),'conflict_count'=>$members->where('kelas_id',$c->id)->filter(fn($m)=>isset($bad[$m->id]))->count()])]);
    }
}
