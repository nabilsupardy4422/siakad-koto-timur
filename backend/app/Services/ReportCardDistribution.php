<?php

namespace App\Services;

use App\Models\Rapor;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReportCardDistribution
{
    public static function membership(int $student, int $year, ?int $class = null): void
    {
        $classes = DB::table('kelas')->where('tahun_akademik_id', $year);
        if ($class !== null) $classes->where('id', $class);
        $today = now('Asia/Jakarta')->toDateString();
        $members = TuAcademicReadService::bounded(DB::table('anggota_kelas')->whereIn('kelas_id',$classes->pluck('id'))->where('siswa_id',$student)->orderBy('id'),30000)->map(fn($m)=>TuAcademicReadService::normalizeMemberDates($m));
        abort_unless($members->contains(fn ($m) => (int)$m->siswa_id === $student && TuAcademicReadService::validInterval($m) && $m->tanggal_mulai <= $today), 422, 'Siswa bukan anggota rombel pada periode yang dipilih.');
    }

    public static function pdf(UploadedFile $file, int $year, int $student): array
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        abort_unless(strtolower($file->getClientOriginalExtension()) === 'pdf' && $file->getMimeType() === 'application/pdf' && $size > 0 && $size <= 10485760, 422, 'Dokumen wajib PDF maksimal 10 MiB.');
        $bytes = file_get_contents($path);
        abort_unless(is_string($bytes) && preg_match('/\A%PDF-\d\.\d/', $bytes) && preg_match('/%%EOF\s*\z/', $bytes), 422, 'Signature atau akhir dokumen PDF tidak valid.');
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        abort_if($name === '' || mb_strlen($name) > 240 || preg_match('/[\x00-\x1f\x7f]/', $name), 422, 'Nama file tidak valid.');
        $target = 'report-cards/'.$year.'/'.$student.'/'.Str::uuid().'.pdf';
        $disk = Storage::disk('local');
        try {
            abort_unless($disk->put($target, $bytes) && $disk->exists($target) && $disk->size($target) === $size, 503, 'Penyimpanan PDF gagal.');
        } catch (\Throwable $e) {
            self::cleanup($target);
            throw $e;
        }
        return ['file_path'=>$target,'file_name'=>$name,'file_mime_type'=>'application/pdf','file_size'=>$size];
    }

    /** Local disk only. A directory or an unreadable file is not a valid document. */
    public static function available(string $path): bool
    {
        $disk=Storage::disk('local');
        $absolute=$disk->path($path);
        if ($disk->fileExists($path)) {
            abort_unless(is_readable($absolute),503,'Storage rapor tidak dapat dibaca.');
            return true;
        }
        $parent=dirname($absolute);
        while (!file_exists($parent) && dirname($parent)!==$parent) $parent=dirname($parent);
        abort_unless(is_dir($parent) && is_readable($parent),503,'Storage rapor tidak dapat diperiksa.');
        return false;
    }

    public static function cleanup(string $path): void
    {
        try {
            if (!Rapor::where('file_path',$path)->exists() && !DB::table('rapor_events')->where('snapshot->file_path',$path)->exists()) {
                $disk=Storage::disk('local');
                if ($disk->exists($path) && !$disk->delete($path)) Log::error('Pembersihan file rapor gagal',['path'=>$path]);
            }
        } catch (\Throwable $e) { Log::error('Pembersihan file rapor tidak dapat dipastikan',['path'=>$path,'error'=>$e->getMessage()]); }
    }

    public static function snapshot(Rapor $r): array
    {
        return ['file_path'=>$r->file_path,'file_name'=>$r->file_name,'file_mime_type'=>$r->file_mime_type,'file_size'=>(int)$r->file_size,'uploaded_by'=>(int)$r->uploaded_by,'uploaded_at'=>$r->uploaded_at?->toISOString()];
    }

    public static function event(Rapor $r, int $actor, string $action, ?string $reason=null): void
    {
        DB::table('rapor_events')->insert(['rapor_id'=>$r->id,'actor_id'=>$actor,'document_version'=>$r->document_version,'action'=>$action,'reason'=>$reason,'snapshot'=>json_encode(self::snapshot($r),JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }

    private static function authorizeActor(int $actor): void
    {
        $user=User::whereKey($actor)->lockForUpdate()->firstOrFail();
        abort_unless($user->role?->code==='TU' && $user->account_status==='active' && !$user->requires_activation,403);
    }

    public static function store(array $d, UploadedFile $file, int $actor): Rapor
    {
        $student=(int)$d['siswa_id'];$year=(int)$d['tahun_akademik_id'];
        self::membership($student,$year,(int)$d['class_id']);
        $where=['siswa_id'=>$student,'tahun_akademik_id'=>$year,'jenis_rapor'=>$d['jenis_rapor']];
        abort_if(Rapor::where($where)->exists(),409,'Dokumen sudah ada. Gunakan revisi atau terbit ulang.');
        $metadata=self::pdf($file,$year,$student);
        try {
            return DB::transaction(function () use ($where,$metadata,$actor,$student,$year,$d) {
                self::authorizeActor($actor);
                Siswa::whereKey($student)->lockForUpdate()->firstOrFail();
                self::membership($student,$year,(int)$d['class_id']);
                abort_if(Rapor::where($where)->exists(),409,'Dokumen sudah ada.');
                $r=Rapor::create($where+$metadata+['uploaded_by'=>$actor,'uploaded_at'=>now()]);
                $r->refresh();self::event($r,$actor,'upload');return $r;
            });
        } catch (\Throwable $e) { self::cleanup($metadata['file_path']);throw $e; }
    }

    public static function change(Rapor $record,array $d,int $actor,string $action,?UploadedFile $file=null): Rapor
    {
        $metadata=null;
        if ($file) { self::membership((int)$record->siswa_id,(int)$record->tahun_akademik_id);$metadata=self::pdf($file,(int)$record->tahun_akademik_id,(int)$record->siswa_id); }
        try {
            return DB::transaction(function () use ($record,$d,$actor,$action,$metadata) {
                self::authorizeActor($actor);
                $r=Rapor::whereKey($record->id)->lockForUpdate()->firstOrFail();
                $expected=$action==='reissue'?'revoked':'active';
                abort_unless((int)$r->document_version===(int)$d['version'] && $r->distribution_status===$expected,409,'Versi atau status berubah. Muat ulang.');
                if ($action==='revoke') {
                    self::event($r,$actor,'revoke',$d['reason']);
                    $r->forceFill(['distribution_status'=>'revoked'])->save();
                } else {
                    self::membership((int)$r->siswa_id,(int)$r->tahun_akademik_id);
                    // Preserve previous metadata, including legacy V1, before overwriting the current row.
                    self::event($r,$actor,'archive',$d['reason']);
                    $r->forceFill($metadata+['document_version'=>(int)$r->document_version+1,'distribution_status'=>'active','uploaded_by'=>$actor,'uploaded_at'=>now()])->save();
                    self::event($r,$actor,$action,$d['reason']);
                }
                return $r;
            });
        } catch (\Throwable $e) { if($metadata) self::cleanup($metadata['file_path']);throw $e; }
    }
}
