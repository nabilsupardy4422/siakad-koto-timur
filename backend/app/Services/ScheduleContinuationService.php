<?php
namespace App\Services;

use App\Models\{Guru,JadwalPelajaran,Kelas,Mapel,TahunAkademik,User};
use App\Support\HomeroomContinuationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleContinuationService
{
    public const MAX_ROWS = 1000;

    public static function run(int $actor, array $input): array
    {
        return ScheduleWriteService::transaction(function () use ($actor,$input) {
            $source=TahunAkademik::findOrFail($input['source_period_id']);
            $target=TahunAkademik::findOrFail($input['target_period_id']);
            abort_unless($source->semester==='GANJIL' && $target->semester==='GENAP' && $source->tahun_mulai===$target->tahun_mulai && $source->tahun_selesai===$target->tahun_selesai,422,'Salin hanya dari Ganjil ke Genap pada tahun ajaran yang sama.');
            $commit=$input['mode']==='commit';
            $token=$input['token']??'';
            $hash=hash('sha256',$token);
            if ($commit && DB::table('schedule_copy_receipts')->where('token_hash',$hash)->exists()) abort(409,'Preview sudah digunakan.');
            $mapping=[];
            foreach ($input['mappings'] as $pair) {
                $a=(int)$pair['source_class_id']; $b=(int)$pair['target_class_id'];
                abort_if(isset($mapping[$a]) || in_array($b,$mapping,true),422,'Pemetaan rombel harus satu-ke-satu.');
                $mapping[$a]=$b;
            }
            ksort($mapping);
            $sourceRows=JadwalPelajaran::where('tahun_akademik_id',$source->id)->orderBy('id')->lockForUpdate()->limit(self::MAX_ROWS+1)->get();
            abort_if($sourceRows->isEmpty() || $sourceRows->count()>self::MAX_ROWS,422,'Jumlah jadwal asal harus 1 sampai 1000.');
            $ids=$sourceRows->pluck('kelas_id')->map(fn($id)=>(int)$id)->unique()->sort()->values()->all();
            abort_unless($ids===array_keys($mapping),422,'Petakan semua rombel asal yang memiliki jadwal, tepat satu kali.');
            $classes=Kelas::whereIn('id',array_merge($ids,array_values($mapping)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $teachers=Guru::whereIn('id',$sourceRows->pluck('guru_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $users=User::whereIn('id',$teachers->pluck('user_id')->filter()->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach($teachers as $g) $g->setRelation('user',$users->get($g->user_id));
            $subjects=Mapel::whereIn('id',$sourceRows->pluck('mapel_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($mapping as $a=>$b) {
                $from=$classes->get($a); $to=$classes->get($b);
                abort_unless($from && $to && (int)$from->tahun_akademik_id===(int)$source->id && (int)$to->tahun_akademik_id===(int)$target->id && (int)$from->tingkat===(int)$to->tingkat,422,'Rombel tujuan harus berada pada semester tujuan dan tingkat yang sama.');
            }
            $destination=JadwalPelajaran::where('tahun_akademik_id',$target->id)->orderBy('id')->lockForUpdate()->get();
            $rows=[]; $groups=[]; $errors=[];
            $track=function(array $d, ?int $rowId) use (&$groups,&$errors) {
                foreach(['kelas_id','guru_id'] as $field) {
                    $k=$field.':'.$d[$field].':'.strtoupper($d['hari']);
                    $start=substr($d['jam_mulai'],0,5); $end=substr($d['jam_selesai'],0,5);
                    foreach($groups[$k]??[] as $other) {
                        if($rowId!==null && $start<$other[1] && $end>$other[0]) $errors['jadwal_'.$rowId][]='Bentrok '.($field==='kelas_id'?'rombel':'guru').' dengan jadwal '.$other[2].'.';
                    }
                    $groups[$k][]=[$start,$end,$rowId===null?'tujuan':'asal #'.$rowId];
                }
            };
            foreach($destination as $d) $track($d->only($d->getFillable()),null);
            foreach($sourceRows as $r) {
                $d=$r->only($r->getFillable()); $d['tahun_akademik_id']=(int)$target->id; $d['kelas_id']=$mapping[(int)$r->kelas_id];
                $d['hari']=strtoupper($d['hari']); $d['jam_mulai']=substr($d['jam_mulai'],0,5); $d['jam_selesai']=substr($d['jam_selesai'],0,5);
                $g=$teachers->get($r->guru_id); $m=$subjects->get($r->mapel_id);
                if(!$g || !ScheduleWriteService::teacherEligible($g)) $errors['jadwal_'.$r->id][]='Guru tidak memenuhi syarat penjadwalan.';
                if(!$m) $errors['jadwal_'.$r->id][]='Mata pelajaran tidak ditemukan.';
                if(!in_array($d['hari'],ScheduleWriteService::DAYS,true) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$d['jam_mulai']) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$d['jam_selesai']) || $d['jam_mulai'] >= $d['jam_selesai']) $errors['jadwal_'.$r->id][]='Hari atau jam asal tidak valid.';
                $track($d,(int)$r->id);
                $rows[]=['source_id'=>(int)$r->id,'source_class'=>$classes[$r->kelas_id]->tingkat.' '.$classes[$r->kelas_id]->nama,'target_class'=>$classes[$d['kelas_id']]->tingkat.' '.$classes[$d['kelas_id']]->nama,'teacher'=>$g?->nama_lengkap,'subject'=>$m?->nama,'account_state'=>$g?->user?->requires_activation?'pending':'ready','data'=>$d];
            }
            $state=['schedule-copy-v1',$actor,$source->only(['id','tahun_mulai','tahun_selesai','semester','is_active']),$target->only(['id','tahun_mulai','tahun_selesai','semester','is_active']),$mapping,$sourceRows->map(fn($r)=>ScheduleWriteService::version($r))->all(),$destination->map(fn($r)=>ScheduleWriteService::version($r))->all(),$rows];
            $key=(string)config('app.key'); $now=now()->timestamp;
            if($commit) abort_unless(HomeroomContinuationToken::verify($token,$state,$now,$key),409,'Preview berubah atau kedaluwarsa. Lakukan preview ulang.');
            if($errors) throw ValidationException::withMessages($errors);
            if(!$commit) return ['rows'=>$rows,'count'=>count($rows),'token'=>HomeroomContinuationToken::issue($state,$now,$key)];
            foreach($rows as $row) { ScheduleWriteService::validate($row['data']); JadwalPelajaran::create($row['data']); }
            DB::table('schedule_copy_receipts')->insert(['token_hash'=>$hash,'actor_id'=>$actor,'source_period_id'=>$source->id,'target_period_id'=>$target->id,'copied_count'=>count($rows),'created_at'=>now()]);
            return ['count'=>count($rows)];
        });
    }
}
