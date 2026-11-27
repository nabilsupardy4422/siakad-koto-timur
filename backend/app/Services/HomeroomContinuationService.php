<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\WaliKelas;
use Illuminate\Support\Facades\DB;
use App\Support\HomeroomContinuationToken as Token;

/** Explicit Ganjil -> Genap, same school year. Preview never writes. */
final class HomeroomContinuationService
{
    public function run(array $data, int $actor): array
    {
        $commit = isset($data['token']);
        $work = function () use ($data, $actor, $commit) {
            $sourceId = (int)$data['source_id']; $targetId = (int)$data['target_id'];
            $periodQuery = TahunAkademik::whereIn('id', [$sourceId,$targetId])->orderBy('id');
            if ($commit) $periodQuery->lockForUpdate();
            $periods = $periodQuery->get()->keyBy('id');
            $source = $periods->get($sourceId); $target = $periods->get($targetId);
            $today = HomeroomAssignmentService::today();
            abort_unless($source && $target && $source->semester === 'GANJIL' && $target->semester === 'GENAP'
                && $source->tahun_mulai === $target->tahun_mulai && $source->tahun_selesai === $target->tahun_selesai, 422, 'Hanya Ganjil ke Genap dalam tahun ajaran yang sama.');
            abort_if($data['tanggal_mulai'] < $today, 422, 'Tanggal mulai kelanjutan tidak boleh sebelum hari ini WIB.');
            $assignments = WaliKelas::where('tahun_akademik_id',$sourceId)->orderBy('id');
            $destinationAssignments = WaliKelas::where('tahun_akademik_id',$targetId)->orderBy('id');
            $classes = Kelas::whereIn('tahun_akademik_id',[$sourceId,$targetId])->orderBy('id');
            if ($commit) { $classes->lockForUpdate(); $assignments->lockForUpdate(); $destinationAssignments->lockForUpdate(); }
            $classMap = $classes->get()->keyBy('id');
            $sourceRows = $assignments->get()->keyBy('id');
            // Include all destination history so a consumed token cannot become valid again after cancellation or same-day closure.
            $targetVersions = $destinationAssignments->get()->map(fn($r)=>HomeroomAssignmentService::version($r))->all();
            $service = app(HomeroomAssignmentService::class);
            // Also reject legacy source conflicts, rather than choosing an arbitrary teacher.
            $sourceStatus = collect($service->workspace($source)['assignments'])->keyBy(fn($r)=>$r['record']->resource->id);
            $rows=[]; $seen=[];
            foreach ($data['rows'] as $pair) {
                $id=(int)$pair['assignment_id']; $classId=(int)$pair['class_id'];
                $old=$sourceRows->get($id); $dest=$classMap->get($classId); $origin=$old ? $classMap->get($old->kelas_id) : null;
                $reason=null; $candidate=null;
                if (!$old || !$origin || (int)$origin->tahun_akademik_id !== $sourceId || $old->cancelled_at !== null || $old->tanggal_selesai !== null || ($sourceStatus->get($id)['status'] ?? null) !== 'effective') $reason='Penugasan asal tidak berlaku atau konflik.';
                elseif (!$dest || (int)$dest->tahun_akademik_id !== $targetId || (int)$dest->tingkat !== (int)$origin->tingkat) $reason='Rombel tujuan harus di semester tujuan dan tingkat yang sama.';
                elseif (isset($seen[$old->guru_id])) $reason='Guru dipilih lebih dari sekali.';
                else {
                    $seen[$old->guru_id]=true;
                    $candidate=collect($service->candidates($dest,$data['tanggal_mulai'],null))->firstWhere('id',$old->guru_id);
                    if (!$candidate) $reason='Guru tidak aktif, tidak valid, atau penugasan tujuan bertabrakan.';
                }
                $rows[]=['assignment_id'=>$id,'class_id'=>$classId,'guru_id'=>$old?->guru_id,
                    'source_version'=>$old ? HomeroomAssignmentService::version($old) : null,
                    'source_class'=>$origin ? ['id'=>$origin->id,'nama'=>$origin->nama,'tingkat'=>$origin->tingkat] : null,
                    'target_class'=>$dest ? ['id'=>$dest->id,'nama'=>$dest->nama,'tingkat'=>$dest->tingkat] : null,
                    'teacher'=>$candidate,'error'=>$reason];
            }
            $valid = !collect($rows)->contains(fn($r)=>$r['error'] !== null);
            $state = [$actor,$sourceId,$targetId,$source->is_active,$target->is_active,$today,$data['tanggal_mulai'],$targetVersions,$rows];
            abort_unless(is_string(config('app.key')) && config('app.key') !== '', 503, 'Konfigurasi signing belum tersedia.');
            $now = now()->timestamp;
            $key = config('app.key');
            if (!$commit) {
                return ['rows'=>$rows,'valid'=>$valid,'token'=>$valid ? Token::issue($state,$now,$key) : null,'as_of'=>$today];
            }
            abort_unless($valid && Token::verify($data['token'],$state,$now,$key),409,'Preview kedaluwarsa atau data berubah. Buat preview ulang.');
            // All writes use the existing period/class/user locks and conflict revalidation.
            foreach ($rows as $row) $service->create(['guru_id'=>$row['guru_id'],'kelas_id'=>$row['class_id'],'tahun_akademik_id'=>$targetId,'tanggal_mulai'=>$data['tanggal_mulai'],'tanggal_selesai'=>null]);
            return ['created'=>count($rows),'target_id'=>$targetId];
        };
        return $commit ? DB::transaction($work,3) : $work();
    }
}
