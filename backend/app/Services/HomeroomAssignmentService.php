<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use App\Support\HomeroomAssignmentRules as Rules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HomeroomAssignmentService
{
    public static function today(): string { return today('Asia/Jakarta')->toDateString(); }
    public static function version(WaliKelas $row): string
    {
        return hash('sha256', json_encode([$row->id, $row->guru_id, $row->kelas_id, $row->tahun_akademik_id,
            $row->tanggal_mulai?->toDateString(), $row->tanggal_selesai?->toDateString(), $row->updated_at?->toISOString(), $row->cancelled_at?->toISOString(), $row->cancelled_by, $row->cancel_reason]));
    }
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
    private function rows(int $year, bool $lock = false): Collection
    {
        $query = WaliKelas::query()->where(fn ($q) => $q->where('tahun_akademik_id', $year)
            ->orWhereHas('kelas', fn ($c) => $c->where('tahun_akademik_id', $year)))->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->with('kelas:id,tahun_akademik_id')->get();
    }
    private function context(int $year, int $class, bool $lock): Kelas
    {
        $period = TahunAkademik::whereKey($year);
        $classes = Kelas::whereKey($class);
        if ($lock) { $period->lockForUpdate(); $classes->lockForUpdate(); }
        $period->firstOrFail();
        $row = $classes->firstOrFail();
        if ((int) $row->tahun_akademik_id !== $year) $this->fail('tahun_akademik_id', 'Periode tidak sesuai dengan kelas.');
        return $row;
    }
    private function eligible(int $guru, bool $lock): bool
    {
        $profile = Guru::find($guru);
        if (! $profile) return false;
        $users = User::whereKey($profile->user_id)->with('role');
        $profiles = Guru::whereKey($guru);
        if ($lock) { $users->lockForUpdate(); $profiles->lockForUpdate(); }
        $user = $users->first();
        $current = $profiles->first();
        return $current && $user && (int) $current->user_id === (int) $user->id && $user->role?->code === 'GURU' && $user->account_status === 'active';
    }
    private function conflict(Collection $rows, array $data, string $today, ?int $ignore = null): ?string
    {
        foreach ($rows as $row) {
            if ((int) $row->id === $ignore || $row->cancelled_at !== null) continue;
            $sameClass = (int) $row->kelas_id === (int) $data['kelas_id'];
            $sameGuru = (int) $row->guru_id === (int) $data['guru_id'];
            if (! $sameClass && ! $sameGuru) continue;
            $start = $row->tanggal_mulai?->toDateString();
            $end = $row->tanggal_selesai?->toDateString();
            if ((int) $row->tahun_akademik_id !== (int) $data['tahun_akademik_id'] || (int) $row->kelas?->tahun_akademik_id !== (int) $data['tahun_akademik_id'] || Rules::invalid($start, $end, $today)
                || Rules::overlaps($data['tanggal_mulai'], $data['tanggal_selesai'] ?? null, $start, $end)) {
                return $sameClass ? 'kelas_id' : 'guru_id';
            }
        }
        return null;
    }
    private function validateWrite(Collection $rows, array $data, string $today, ?int $ignore = null): void
    {
        if (Rules::invalid($data['tanggal_mulai'], $data['tanggal_selesai'] ?? null, $today)) {
            $this->fail('tanggal_selesai', 'Tanggal selesai harus antara tanggal mulai dan hari ini. Penutupan terjadwal tidak didukung.');
        }
        if ($field = $this->conflict($rows, $data, $today, $ignore)) {
            $this->fail($field, $field === 'kelas_id' ? 'Kelas sudah memiliki penugasan yang bertabrakan atau konflik data.' : 'Guru sudah memiliki penugasan yang bertabrakan atau konflik data pada periode ini.');
        }
    }
    public function create(array $data): WaliKelas
    {
        return DB::transaction(function () use ($data) {
            $year = (int) $data['tahun_akademik_id'];
            $this->context($year, (int) $data['kelas_id'], true);
            if (! $this->eligible((int) $data['guru_id'], true)) $this->fail('guru_id', 'Guru harus terhubung ke akun GURU aktif (termasuk menunggu aktivasi).');
            $this->validateWrite($this->rows($year, true), $data, self::today());
            return WaliKelas::create($data);
        }, 3);
    }
    private function locked(WaliKelas $original, ?string $version): WaliKelas
    {
        $row = WaliKelas::whereKey($original->id)->lockForUpdate()->firstOrFail();
        abort_if((int) $row->tahun_akademik_id !== (int) $original->tahun_akademik_id || (int) $row->kelas_id !== (int) $original->kelas_id, 409, 'Penugasan berubah. Muat ulang.');
        abort_if($version !== null && ! hash_equals(self::version($row), $version), 409, 'Penugasan berubah. Muat ulang.');
        return $row;
    }
    public function update(WaliKelas $original, array $data): WaliKelas
    {
        return DB::transaction(function () use ($original, $data) {
            $year = (int) $original->tahun_akademik_id;
            $this->context($year, (int) $original->kelas_id, true);
            $row = $this->locked($original, $data['version'] ?? null);
            abort_if($row->tanggal_selesai !== null || $row->cancelled_at !== null, 409, 'Riwayat penugasan tidak dapat diubah atau dibuka kembali.');
            foreach (['guru_id', 'kelas_id', 'tahun_akademik_id'] as $field) {
                if (array_key_exists($field, $data) && (int) $data[$field] !== (int) $row->{$field}) $this->fail($field, 'Identitas penugasan tetap. Gunakan pergantian wali atau penugasan baru.');
            }
            $start = $data['tanggal_mulai'] ?? $row->tanggal_mulai?->toDateString();
            $end = $data['tanggal_selesai'] ?? null;
            if ($start === null) $this->fail('tanggal_mulai', 'Tanggal mulai tidak valid.');
            if ($end !== null && $end !== self::today()) $this->fail('tanggal_selesai', 'Gunakan pengakhiran hari ini.');
            if ($end === null && !$this->eligible((int)$row->guru_id, true)) $this->fail('guru_id', 'Akun guru tidak aktif.');
            $payload = ['guru_id' => $row->guru_id, 'kelas_id' => $row->kelas_id, 'tahun_akademik_id' => $year, 'tanggal_mulai' => $start, 'tanggal_selesai' => $end];
            $this->validateWrite($this->rows($year, true), $payload, self::today(), (int) $row->id);
            $row->update(['tanggal_mulai' => $start, 'tanggal_selesai' => $end]);
            return $row;
        }, 3);
    }
    public function end(WaliKelas $original, ?string $version, ?string $asOf = null): void
    {
        DB::transaction(function () use ($original, $version, $asOf) {
            // Even a mismatched legacy row can be closed deliberately; never rewrite its identity.
            TahunAkademik::whereKey($original->tahun_akademik_id)->lockForUpdate()->firstOrFail();
            Kelas::whereKey($original->kelas_id)->lockForUpdate()->firstOrFail();
            $row = $this->locked($original, $version);
            abort_if($row->cancelled_at !== null, 409, 'Penugasan sudah dibatalkan.');
            if ($row->tanggal_selesai !== null) return;
            $today = self::today();
            abort_if($asOf !== null && $asOf !== $today, 409, 'Tanggal server berubah. Muat ulang.');
            if ($row->tanggal_mulai === null || $row->tanggal_mulai->toDateString() > $today) $this->fail('tanggal_mulai', 'Penugasan belum dimulai atau tanggal tidak valid.');
            $row->update(['tanggal_selesai' => $today]);
        }, 3);
    }
    public function replace(WaliKelas $original, int $guru, string $version, ?string $asOf = null): WaliKelas
    {
        return DB::transaction(function () use ($original, $guru, $version, $asOf) {
            $year = (int) $original->tahun_akademik_id;
            $this->context($year, (int) $original->kelas_id, true);
            if (! $this->eligible($guru, true)) $this->fail('guru_id', 'Profil guru tidak valid.');
            $row = $this->locked($original, $version);
            $today = self::today();
            abort_if($asOf !== null && $asOf !== $today, 409, 'Tanggal server berubah. Muat ulang.');
            abort_unless($row->cancelled_at === null && Rules::status($row->tanggal_mulai?->toDateString(), $row->tanggal_selesai?->toDateString(), $today) === 'effective', 409, 'Penugasan lama tidak berlaku.');
            if ((int) $row->guru_id === $guru) $this->fail('guru_id', 'Pilih guru pengganti yang berbeda.');
            $data = ['guru_id' => $guru, 'kelas_id' => $row->kelas_id, 'tahun_akademik_id' => $year, 'tanggal_mulai' => $today, 'tanggal_selesai' => null];
            $this->validateWrite($this->rows($year, true), $data, $today, (int) $row->id);
            $row->update(['tanggal_selesai' => $today]);
            return WaliKelas::create($data);
        }, 3);
    }
    public function cancel(WaliKelas $original, string $version, string $asOf, int $actor, string $reason): void
    {
        DB::transaction(function () use ($original, $version, $asOf, $actor, $reason) {
            $this->context((int)$original->tahun_akademik_id, (int)$original->kelas_id, true);
            $row = $this->locked($original, $version);
            $today = self::today();
            abort_unless($asOf === $today && $row->cancelled_at === null && Rules::status($row->tanggal_mulai?->toDateString(), $row->tanggal_selesai?->toDateString(), $today) === 'scheduled', 409, 'Hanya penugasan belum mulai dapat dibatalkan. Muat ulang.');
            $row->update(['cancelled_at'=>now(), 'cancelled_by'=>$actor, 'cancel_reason'=>$reason]);
        }, 3);
    }

    public function candidates(Kelas $class, string $start, ?int $replace): array
    {
        $year = (int) $class->tahun_akademik_id;
        $today = self::today();
        $rows = $this->rows($year);
        $old = $replace === null ? null : $rows->firstWhere('id', $replace);
        if ($replace !== null) abort_unless($old && $old->cancelled_at === null && (int) $old->kelas_id === (int) $class->id && (int) $old->tahun_akademik_id === $year && $start === $today && Rules::status($old->tanggal_mulai?->toDateString(), $old->tanggal_selesai?->toDateString(), $today) === 'effective', 409, 'Penugasan pengganti tidak valid.');
        return Guru::whereHas('user', fn ($q) => $q->where('account_status','active')->whereHas('role', fn ($r) => $r->where('code','GURU')))->with('user')->orderBy('nama_lengkap')->orderBy('id')->get()
            ->filter(function ($guru) use ($rows, $year, $class, $start, $today, $replace, $old) {
                if ($old && (int) $old->guru_id === (int) $guru->id) return false;
                return ! $this->conflict($rows, ['guru_id' => $guru->id, 'kelas_id' => $class->id, 'tahun_akademik_id' => $year, 'tanggal_mulai' => $start], $today, $replace);
            })->map(fn ($g) => ['id' => $g->id, 'nama_lengkap' => $g->nama_lengkap, 'nip' => $g->nip,
                'account_state' => $g->user->account_status !== 'active' ? 'inactive' : ($g->user->requires_activation ? 'pending' : 'ready')])->values()->all();
    }
    public function workspace(TahunAkademik $period): array
    {
        $year = (int) $period->id;
        $today = self::today();
        $classes = Kelas::where('tahun_akademik_id', $year)->orderBy('tingkat')->orderBy('nama')->orderBy('id')->get(['id','nama','tingkat']);
        $rows = $this->rows($year)->load(['guru.user', 'kelas', 'tahunAkademik']);
        $conflicts = [];
        $statuses = [];
        foreach ($rows as $row) {
            $start = $row->tanggal_mulai?->toDateString(); $end = $row->tanggal_selesai?->toDateString();
            $status = $row->cancelled_at !== null ? 'cancelled' : Rules::status($start, $end, $today);
            if ((int) $row->tahun_akademik_id !== $year || (int) $row->kelas?->tahun_akademik_id !== $year) $status = 'conflict';
            if (!in_array($status, ['conflict','cancelled'], true) && $this->conflict($rows, ['guru_id'=>$row->guru_id, 'kelas_id'=>$row->kelas_id, 'tahun_akademik_id'=>$year, 'tanggal_mulai'=>$start, 'tanggal_selesai'=>$end], $today, (int) $row->id)) $status = 'conflict';
            if ($status === 'conflict') $conflicts[(int) $row->kelas_id] = true;
            $statuses[$row->id] = $status;
        }
        $mapping = $classes->map(function ($class) use ($rows, $statuses, $conflicts) {
            $matching = $rows->where('kelas_id', $class->id);
            $open = $matching->filter(fn ($r) => $r->tanggal_selesai === null && $r->cancelled_at === null)->values();
            $status = isset($conflicts[$class->id]) ? 'conflict' : ($open->isEmpty() ? 'unassigned' : $statuses[$open->first()->id]);
            return ['id'=>$class->id, 'nama'=>$class->nama, 'tingkat'=>$class->tingkat, 'status'=>$status,
                'assignment_ids'=>$open->pluck('id')->all()];
        });
        $summary = ['total'=>$mapping->count()];
        foreach (['effective','scheduled','unassigned','conflict'] as $status) $summary[$status] = $mapping->where('status',$status)->count();
        $active = TahunAkademik::where('is_active',true)->pluck('id');
        return ['period_id'=>$year, 'as_of'=>$today, 'active_state'=>$active->count() === 1 ? ((int)$active->first() === $year ? 'active' : 'inactive') : ($active->isEmpty() ? 'none' : 'conflict'),
            'summary'=>$summary, 'classes'=>$mapping->all(), 'assignments'=>$rows->map(fn ($r) => ['record'=>new \App\Http\Resources\WaliKelasResource($r), 'status'=>$statuses[$r->id], 'account_state'=>!$r->guru?->user ? 'unknown' : ($r->guru->user->account_status !== 'active' ? 'inactive' : ($r->guru->user->requires_activation ? 'pending' : 'ready'))])->all()];
    }
}
