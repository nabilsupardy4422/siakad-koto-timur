<?php

namespace App\Services\Reporting;

use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\User;

final class ReportWorkspace
{
    /** Server-authorized options; no client-supplied guru/year/class IDs. */
    public static function context(User $user): array
    {
        abort_unless($user->role?->code === 'GURU' && $user->guru?->id !== null, 403);
        $years = TahunAkademik::query()->where('is_active', true)->get();
        abort_if($years->count() > 1, 409, 'Lebih dari satu periode aktif. Hubungi Tata Usaha.');
        $year = $years->first();
        if (!$year) return ['year' => null, 'teaching' => ['classes' => [], 'schedules' => []], 'homeroom' => ['classes' => [], 'schedules' => []]];
        $result = ['year' => ['id' => $year->id, 'label' => "$year->tahun_mulai/$year->tahun_selesai · $year->semester"]];
        foreach (['teaching', 'homeroom'] as $scope) {
            $filters = ['scope' => $scope, 'academic_year_id' => (int) $year->id];
            $ids = ReportAccess::classIds($user, $filters);
            $classes = Kelas::query()->whereIn('id', $ids)->orderBy('tingkat')->orderBy('nama')->orderBy('id')->get()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->nama.' · Tingkat '.$c->tingkat])->values()->all();
            $schedules = ReportAccess::schedules($user, $filters)->with(['kelas', 'mapel', 'guru'])->orderBy('id')->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'class_id' => $s->kelas_id, 'subject_id' => $s->mapel_id,
                    'subject' => $s->mapel?->nama, 'class' => $s->kelas?->nama.' · Tingkat '.$s->kelas?->tingkat,
                    'teacher' => $s->guru?->nama_lengkap, 'day' => $s->hari,
                    'start' => substr((string) $s->jam_mulai, 0, 5), 'end' => substr((string) $s->jam_selesai, 0, 5),
                ])->values()->all();
            $result[$scope] = compact('classes', 'schedules');
        }
        return $result;
    }

    /** Detect changed report content; not an authorization token or signature. */
    public static function fingerprint(string $type, array $report, array $meta): string
    {
        $filters = $report['effective_filters'];
        unset($filters['format'], $filters['page'], $filters['preview_hash']);
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') unset($filters[$key]);
            elseif (is_scalar($value)) $filters[$key] = (string) $value;
        }
        ksort($filters);
        unset($meta['generated']); // time is allowed to change on export
        return hash('sha256', json_encode([$type, $filters, $meta, $report['headings'], $report['rows']->values()->all()], JSON_THROW_ON_ERROR));
    }
}
