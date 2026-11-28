<?php
namespace App\Services;

use App\Models\{AcademicMembershipEvent,AnggotaKelas,Kelas,Siswa,TahunAkademik,Presensi,SubmissionTugas};
use App\Support\AcademicTransitionRules as Rules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Administrative operations only. Preserve existing membership IDs and academic records. */
final class AcademicTransitionService {
    public function snapshot(array $data, bool $lock=false): array {
        $date=$data['effective_on'];
        // No retrospective effective date; same-day closure is checked against dated records.
        $today=today('Asia/Jakarta')->toDateString();
        abort_if($date<$today,422,'Tanggal efektif tidak boleh mundur.');
        $end=Carbon::parse($date,'Asia/Jakarta')->subDay()->toDateString();
        $items=collect($data['items'])->sortBy('membership_id')->values();
        $periodIds=array_values(array_unique([(int)$data['source_period_id'],(int)$data['target_period_id']]));
        $periodQuery=TahunAkademik::whereIn('id',$periodIds)->orderBy('id');
        if($lock) $periodQuery->lockForUpdate();
        $periods=$periodQuery->get()->keyBy('id');
        $from=$periods->get($data['source_period_id']);$to=$periods->get($data['target_period_id']);
        abort_unless($from && $to,422,'Periode asal atau tujuan tidak ditemukan.');
        $mode=$from->id===$to->id?'same':Rules::nextPeriod($from->toArray(),$to->toArray());
        abort_unless($mode,422,'Pilih periode sama atau semester berikutnya yang berurutan.');
        $classQuery=Kelas::whereIn('tahun_akademik_id',$periodIds)->orderBy('id');
        if($lock) $classQuery->lockForUpdate();
        $classes=$classQuery->get()->keyBy('id');
        $sources=AnggotaKelas::whereIn('id',$items->pluck('membership_id'))->get()->keyBy('id');
        $studentIds=$sources->pluck('siswa_id')->unique()->values();
        $sq=Siswa::whereIn('id',$studentIds)->orderBy('id');if($lock)$sq->lockForUpdate();
        $students=$sq->get()->keyBy('id');
        $mq=AnggotaKelas::whereIn('siswa_id',$studentIds)->orderBy('id');if($lock)$mq->lockForUpdate();
        $histories=$mq->get()->groupBy('siswa_id');
        $eq=AcademicMembershipEvent::whereIn('siswa_id',$studentIds)->orderBy('id');if($lock)$eq->lockForUpdate();
        $events=$eq->get()->groupBy('siswa_id');
        $rows=[];$states=[];$seen=$sources->pluck('siswa_id')->countBy();
        // Reload source membership from locked history, not the initial discovery query.
        foreach($items as $item){
            $initial=$sources->get($item['membership_id']);$student=$students->get($initial?->siswa_id);
            $history=$histories->get($student?->id,collect());$source=$history->firstWhere('id',$item['membership_id']);
            $origin=$classes->get($source?->kelas_id);$dest=isset($item['destination_class_id'])?$classes->get($item['destination_class_id']):null;
            $action=$item['action'];$terminal=in_array($action,['graduate','withdraw'],true);$reason=null;
            $studentEvents=$events->get($student?->id,collect());
            if(!$source||!$student||!$origin||(int)$origin->tahun_akademik_id!==(int)$from->id) $reason='Keanggotaan asal tidak sesuai periode.';
            elseif(($seen[$student->id]??0)>1) $reason='Satu siswa hanya boleh diproses sekali per batch.';
            elseif($studentEvents->contains(fn($e)=>$e->source_membership_id==$source->id || in_array($e->action,['graduate','withdraw'],true))) $reason='Keanggotaan sudah diproses atau siswa sudah lulus/pindah.';
            elseif(!$source->tanggal_mulai || $source->tanggal_mulai->toDateString()>$end || ($source->tanggal_selesai && $source->tanggal_selesai->toDateString()!==$end)) $reason='Asal harus mencakup hari terakhir sebelum tanggal efektif, tanpa mengubah tanggal selesai yang sudah ditetapkan.';
            elseif(($terminal && !empty($item['destination_class_id'])) || (!$terminal && (!$dest || $dest->id===$origin->id || (int)$dest->tahun_akademik_id!==(int)$to->id))) $reason='Kelas tujuan tidak sesuai.';
            elseif(!Rules::allows($action,(int)$origin->tingkat,$dest?->tingkat,$mode)) $reason='Keputusan tidak sesuai tingkat atau urutan periode.';
            elseif($terminal && $date!==$today) $reason='Kelulusan/pindah keluar hanya boleh dicatat pada tanggal efektif hari ini.';
            else {
                foreach($history as $other){
                    if($other->id===$source->id) continue;
                    $otherStart=$other->tanggal_mulai?->toDateString();$otherEnd=$other->tanggal_selesai?->toDateString();
                    // Refuse any invalid interval, source overlap, or later membership across periods.
                    if(!$otherStart || ($otherEnd && $otherEnd<$otherStart) || Rules::overlap($source->tanggal_mulai->toDateString(),$end,$otherStart,$otherEnd) || Rules::overlap($date,null,$otherStart,$otherEnd)) { $reason='Ada riwayat konflik atau penempatan lain yang masih berlaku/mendatang. Periksa terlebih dahulu.';break; }
                }
                if(!$reason){
                    $attendance=Presensi::where('siswa_id',$student->id)->whereDate('tanggal','>',$end)->whereHas('jadwalPelajaran',fn($q)=>$q->where('kelas_id',$origin->id));
                    $submission=SubmissionTugas::where('siswa_id',$student->id)->where('submitted_at','>=',Carbon::parse($date,'Asia/Jakarta')->utc()->toDateTimeString())->whereHas('assignment.jadwalPelajaran',fn($q)=>$q->where('kelas_id',$origin->id));
                    if($lock){$attendance->lockForUpdate();$submission->lockForUpdate();}
                    if($attendance->first(['id'])||$submission->first(['id'])) $reason='Ada presensi atau pengumpulan tugas setelah tanggal akhir asal.';
                }
            }
            $rows[]=['membership_id'=>(int)$item['membership_id'],'siswa_id'=>$student?->id,'nama_lengkap'=>$student?->nama_lengkap,'nisn'=>$student?->nisn,'source'=>$origin?->nama,'source_level'=>$origin?->tingkat,'destination'=>$dest?->nama,'destination_level'=>$dest?->tingkat,'action'=>$action,'valid'=>$reason===null,'keterangan'=>$reason??'Siap diproses.'];
            $states[]=[$item,$student?->id,$student?->nama_lengkap,$student?->nisn,$origin?->directoryVersion(),$dest?->directoryVersion(),$history->map(fn($m)=>[$m->id,$m->kelas_id,$m->tanggal_mulai?->toDateString(),$m->tanggal_selesai?->toDateString()])->values()->all(),$studentEvents->pluck('id')->all(),$reason];
        }
        return ['rows'=>$rows,'state'=>[$from->toArray(),$to->toArray(),$date,$states],'end'=>$end];
    }
    public function commit(array $data,int $actor,ClassMembershipService $signer): int {
        return DB::transaction(function()use($data,$actor,$signer){
            $snapshot=$this->snapshot($data,true);
            $signer->verify($snapshot,$actor,'transition',$data['preview_token']);
            abort_if(collect($snapshot['rows'])->contains(fn($r)=>!$r['valid']),422,'Seluruh keputusan harus valid. Tidak ada perubahan disimpan.');
            foreach($data['items'] as $item){
                $member=AnggotaKelas::whereKey($item['membership_id'])->lockForUpdate()->firstOrFail();
                $member->update(['tanggal_selesai'=>$snapshot['end']]);
                $dest=null;
                if(!in_array($item['action'],['graduate','withdraw'],true)) $dest=AnggotaKelas::create(['kelas_id'=>$item['destination_class_id'],'siswa_id'=>$member->siswa_id,'tanggal_mulai'=>$data['effective_on'],'tanggal_selesai'=>null]);
                AcademicMembershipEvent::create(['siswa_id'=>$member->siswa_id,'source_membership_id'=>$member->id,'destination_membership_id'=>$dest?->id,'actor_id'=>$actor,'action'=>$item['action'],'effective_on'=>$data['effective_on']]);
            }
            return count($data['items']);
        },3);
    }
}
