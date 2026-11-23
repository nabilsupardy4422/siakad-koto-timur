<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnggotaKelas;
use App\Models\KomponenNilai;
use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Workspace nilai untuk SISWA saja. Tidak menerima student_id / class_id override.
 * Tahun historis boleh dilihat selama keanggotaan pernah efektif.
 * Jadwal rutin bukan bukti tanggal penilaian (nilai belum memiliki tanggal penilaian).
 */
final class StudentGradeWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $user->loadMissing(['role', 'siswa']);
        if ($user->role?->code !== 'SISWA') {
            return response()->json(['message' => 'Akses hanya untuk siswa.'], 403);
        }

        $yearId = $validated['academic_year_id'] ?? null;
        if ($yearId !== null) {
            $year = TahunAkademik::query()->findOrFail($yearId);
        } else {
            $active = TahunAkademik::query()->where('is_active', true)->limit(2)->get();
            if ($active->count() !== 1) {
                return response()->json(['message' => 'Periode aktif belum tunggal.'], 409);
            }
            $year = $active->first();
        }

        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $studentId = $user->siswa?->id;
        $base = [
            'year' => [
                'id' => (int) $year->id,
                'label' => $year->tahun_mulai.'/'.$year->tahun_selesai,
                'semester' => $year->semester,
                'is_active' => (bool) $year->is_active,
            ],
            'as_of' => $today,
            'membership_status' => 'unassigned',
            'classes' => [],
            'subjects' => [],
            'summary' => ['subjects' => 0, 'components' => 0, 'assessed' => 0, 'pending' => 0],
        ];
        if ($studentId === null) {
            return response()->json(['data' => $base]);
        }

        $memberships = AnggotaKelas::query()
            ->with('kelas')
            ->where('siswa_id', $studentId)
            ->whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $year->id))
            ->whereNotNull('tanggal_mulai')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->get();

        // Periode aktif memakai kelas efektif saat ini. Historis mempertahankan
        // kelas yang sudah pernah dimulai, termasuk penempatan yang telah berakhir.
        $eligible = $memberships->filter(function ($member) use ($year, $today) {
            return ! $year->is_active ||
                $member->tanggal_selesai === null ||
                $member->tanggal_selesai->toDateString() >= $today;
        })->values();

        if ($year->is_active && $eligible->pluck('kelas_id')->unique()->count() > 1) {
            return response()->json(['message' => 'Penempatan kelas aktif siswa ambigu.'], 409);
        }

        $classIds = $eligible->pluck('kelas_id')->unique()->values()->all();
        if ($classIds === []) {
            $base['membership_status'] = $memberships->isNotEmpty() ? 'ended' : 'unassigned';
            return response()->json(['data' => $base]);
        }

        $base['membership_status'] = $year->is_active ? 'active' : 'historical';
        $base['classes'] = $eligible->unique('kelas_id')->map(fn ($m) => [
            'id' => (int) $m->kelas_id,
            'name' => $m->kelas?->nama,
            'level' => $m->kelas?->tingkat,
        ])->values()->all();

        // Jadwal menjadi sumber kartu mapel, termasuk mapel yang BELUM
        // memiliki satu pun komponen nilai (bukan dianggap tidak ada mapel).
        $schedules = JadwalPelajaran::query()
            ->with(['kelas', 'mapel', 'guru'])
            ->where('tahun_akademik_id', $year->id)
            ->whereIn('kelas_id', $classIds)
            ->whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $year->id))
            ->get();

        $subjects = [];
        foreach ($schedules as $schedule) {
            if (! $schedule->mapel || ! $schedule->kelas || ! $schedule->guru) {
                continue;
            }
            $key = $schedule->kelas_id.':'.$schedule->mapel_id;
            if (! isset($subjects[$key])) {
                $subjects[$key] = [
                    'key' => $key,
                    'subject_id' => (int) $schedule->mapel_id,
                    'subject' => $schedule->mapel->nama,
                    'class_id' => (int) $schedule->kelas_id,
                    'class_name' => $schedule->kelas->nama,
                    'teachers' => [],
                    'components' => [],
                    'assessed' => 0,
                    'pending' => 0,
                ];
            }
            $subjects[$key]['teachers'][(string) $schedule->guru_id] = [
                'id' => (int) $schedule->guru_id,
                'name' => $schedule->guru->nama_lengkap,
            ];
        }

        // Eager-load nilai milik siswa ini SAJA; jangan gunakan daftar nilai kelas.
        $components = KomponenNilai::query()
            ->whereIn('jadwal_pelajaran_id', $schedules->pluck('id')->all())
            ->with([
                'jadwalPelajaran.kelas', 'jadwalPelajaran.mapel', 'jadwalPelajaran.guru',
                'nilai' => fn ($q) => $q->where('siswa_id', $studentId),
            ])
            ->orderBy('id')
            ->get();

        foreach ($components as $component) {
            $schedule = $component->jadwalPelajaran;
            if (! $schedule || ! $schedule->mapel || ! $schedule->kelas || ! $schedule->guru) {
                continue;
            }
            // Components remain tied to their original schedule and teacher.
            $key = $schedule->kelas_id.':'.$schedule->mapel_id;
            if (! isset($subjects[$key])) {
                continue;
            }
            $grade = $component->nilai->first();
            $assessed = $grade !== null && $grade->nilai !== null;
            $subjects[$key]['components'][] = [
                'id' => (int) $component->id,
                'schedule_id' => (int) $schedule->id,
                'teacher' => $schedule->guru->nama_lengkap,
                'day' => $schedule->hari,
                'start' => substr((string) $schedule->jam_mulai, 0, 5),
                'end' => substr((string) $schedule->jam_selesai, 0, 5),
                'name' => $component->nama,
                'weight' => $component->bobot === null ? null : (float) $component->bobot,
                'score' => $assessed ? (float) $grade->nilai : null,
                'note' => $grade?->catatan,
                'assessed' => $assessed,
            ];
            $subjects[$key][$assessed ? 'assessed' : 'pending']++;
        }

        $base['subjects'] = collect($subjects)->map(function ($subject) {
            $subject['teachers'] = array_values($subject['teachers']);
            return $subject;
        })->sortBy('subject', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $base['summary'] = [
            'subjects' => count($base['subjects']),
            'components' => $components->count(),
            'assessed' => array_sum(array_column($base['subjects'], 'assessed')),
            'pending' => array_sum(array_column($base['subjects'], 'pending')),
        ];
        // Nilai akhir / rerata berbobot sengaja TIDAK dihitung.
        return response()->json(['data' => $base])->header('Cache-Control', 'private, no-store');
    }
}
