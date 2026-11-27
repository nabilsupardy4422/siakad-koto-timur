<?php

namespace App\Support;

use App\Models\TahunAkademik;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class StudentPlacement
{
    public static function period(Request $request): ?int
    {
        if ($request->filled('academic_year_id')) {
            $request->validate(['academic_year_id' => ['integer', 'exists:tahun_akademik,id']]);
            return $request->integer('academic_year_id');
        }
        $ids = TahunAkademik::query()->where('is_active', true)->limit(2)->pluck('id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    public static function scope(Builder $q, int $period, ?int $classId = null, bool $effective = true): void
    {
        $q->whereHas('kelas', fn (Builder $c) => $c->where('tahun_akademik_id', $period));
        if ($classId !== null) {
            $q->where('kelas_id', $classId);
        }
        if ($effective) {
            $today = now('Asia/Jakarta')->toDateString();
            $q->whereDate('tanggal_mulai', '<=', $today)
                ->where(fn (Builder $end) => $end->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $today));
        }
    }

    public static function load(Builder $query, ?int $period): void
    {
        $query->with(['academicMembershipEvents'=>fn($q)=>$q->whereIn('action',['graduate','withdraw'])->whereDate('effective_on','<=',today('Asia/Jakarta')->toDateString())->orderBy('id')]);
        if ($period === null) {
            return;
        }
        $query->with(['anggotaKelas' => function ($q) use ($period): void {
            self::scope($q->getQuery(), $period, null, false);
            $q->with('kelas');
        }]);
    }

    public static function summary($student, ?int $period): array
    {
        $terminal=$student->relationLoaded('academicMembershipEvents')?$student->academicMembershipEvents->last()?->action:$student->academicMembershipEvents()->whereIn('action',['graduate','withdraw'])->whereDate('effective_on','<=',today('Asia/Jakarta')->toDateString())->latest('id')->first()?->action;
        // Keep historical membership rows visible; status is a separate decision, not account state.
        if ($period === null || ! $student->relationLoaded('anggotaKelas')) {
            return ['status' => 'period_unavailable', 'class_name' => null, 'academic_year_id' => $period,'academic_status'=>$terminal];
        }
        $today = now('Asia/Jakarta')->toDateString();
        $rows = $student->anggotaKelas;
        $effective = $rows->filter(fn ($r) => $r->tanggal_mulai->toDateString() <= $today && (! $r->tanggal_selesai || $r->tanggal_selesai->toDateString() >= $today));
        $status = $effective->count() > 1 ? 'conflict' : ($effective->count() === 1 ? 'effective' : ($rows->isEmpty() ? 'unassigned' : ($rows->contains(fn ($r) => $r->tanggal_mulai->toDateString() > $today) ? 'future' : 'historical')));
        return ['status' => $status, 'class_name' => $effective->count() === 1 ? $effective->first()->kelas?->nama : ($status === 'historical' && $rows->count() === 1 ? $rows->first()->kelas?->nama : null),
            'academic_year_id' => $period, 'academic_status'=>$terminal, 'memberships' => $rows->map(fn ($r) => ['id' => $r->id, 'class_name' => $r->kelas?->nama, 'start' => $r->tanggal_mulai->toDateString(), 'end' => $r->tanggal_selesai?->toDateString()])->values()];
    }
}
