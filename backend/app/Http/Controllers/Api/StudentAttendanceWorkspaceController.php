<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JadwalPelajaran;
use App\Models\Presensi;
use App\Models\TahunAkademik;
use App\Support\StudentScheduleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only student's own attendance, bound to the one active effective class. */
final class StudentAttendanceWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::today('Asia/Jakarta')->toDateString()],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::today('Asia/Jakarta')->toDateString(), 'after_or_equal:date_from'],
        ]);

        $years = TahunAkademik::query()->where('is_active', true)->limit(2)->get();
        if ($years->count() !== 1) {
            return response()->json(['message' => 'Periode akademik aktif tidak tersedia atau tidak tunggal. Hubungi Tata Usaha.'], 409);
        }

        $year = $years->first();
        $studentId = $request->user()->siswa?->id;
        $context = StudentScheduleAccess::resolve($studentId, $year);
        if ($context['status'] === 'ambiguous') {
            return response()->json(['message' => 'Penempatan kelas siswa tidak tunggal. Hubungi Tata Usaha.'], 409);
        }
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $empty = ['data' => ['context' => $context, 'subjects' => [], 'history' => []],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => (int) ($filters['per_page'] ?? 10)]];
        if ($context['status'] !== 'active' || !$studentId) {
            return response()->json($empty)->header('Cache-Control', 'private, no-store');
        }

        $classId = (int) $context['class']['id'];
        $scheduleQuery = JadwalPelajaran::query()
            ->where('tahun_akademik_id', $year->id)->where('kelas_id', $classId);
        $schedules = $scheduleQuery->with(['mapel:id,nama', 'guru:id,nama_lengkap'])
            ->orderBy('hari')->orderBy('jam_mulai')->get();
        $scheduleIds = $schedules->pluck('id');

        // Dates must be valid membership dates. A future / missing record is NOT an absence.
        $base = Presensi::query()
            ->where('siswa_id', $studentId)
            ->whereIn('jadwal_pelajaran_id', $scheduleIds)
            ->whereDate('tanggal', '<=', $today)
            ->whereIn('presensi.id', \App\Services\TeacherMonitoringIntegrity::attendanceIds())
            ->whereExists(function ($q) use ($classId, $studentId): void {
                $q->selectRaw('1')->from('anggota_kelas as ak')
                    ->where('ak.kelas_id', $classId)->where('ak.siswa_id', $studentId)
                    ->whereNotNull('ak.tanggal_mulai')
                    ->whereColumn('ak.tanggal_mulai', '<=', 'presensi.tanggal')
                    ->where(function ($inner): void {
                        $inner->whereNull('ak.tanggal_selesai')
                            ->orWhereColumn('ak.tanggal_selesai', '>=', 'presensi.tanggal');
                    });
            });
        if (!empty($filters['date_from'])) $base->whereDate('tanggal', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $base->whereDate('tanggal', '<=', $filters['date_to']);

        $summaries = (clone $base)->select('jadwal_pelajaran_id', DB::raw('UPPER(status) as status'), DB::raw('COUNT(*) as total'))
            ->groupBy('jadwal_pelajaran_id', DB::raw('UPPER(status)'))->get();
        $counts = [];
        foreach ($summaries as $item) {
            $id = (int) $item->jadwal_pelajaran_id;
            $counts[$id][$item->status] = (int) $item->total;
        }
        $subjects = [];
        foreach ($schedules as $schedule) {
            if (!$schedule->mapel) continue;
            $id = (int) $schedule->mapel_id;
            if (!isset($subjects[$id])) {
                $subjects[$id] = ['id' => $id, 'name' => $schedule->mapel->nama,
                    'schedules' => [], 'summary' => ['HADIR' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0, 'total' => 0]];
            }
            $subjects[$id]['schedules'][] = ['id' => $schedule->id, 'teacher' => $schedule->guru?->nama_lengkap ?? 'Belum ditentukan',
                'day' => $schedule->hari, 'start' => $schedule->jam_mulai, 'end' => $schedule->jam_selesai];
            foreach (['HADIR', 'IZIN', 'SAKIT', 'ALPA'] as $status) {
                $value = $counts[$schedule->id][$status] ?? 0;
                $subjects[$id]['summary'][$status] += $value;
                $subjects[$id]['summary']['total'] += $value;
            }
        }
        $subjects = array_values($subjects);
        usort($subjects, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $selectedSubject = $filters['subject_id'] ?? null;
        if ($selectedSubject !== null && !collect($subjects)->contains('id', (int) $selectedSubject)) {
            return response()->json(['message' => 'Mata pelajaran tidak tersedia pada kelas aktif siswa.'], 422);
        }
        $history = (clone $base)->with(['jadwalPelajaran.mapel', 'jadwalPelajaran.guru']);
        if ($selectedSubject !== null) {
            $validIds = $schedules->where('mapel_id', (int) $selectedSubject)->pluck('id');
            $history->whereIn('jadwal_pelajaran_id', $validIds);
        } else {
            // Cards screen: load no history until a subject is chosen.
            $history->whereRaw('1 = 0');
        }
        $page = $history->orderByDesc('tanggal')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
        $rows = $page->getCollection()->map(function ($record) {
            $schedule = $record->jadwalPelajaran;
            return ['id' => $record->id, 'date' => $record->tanggal->toDateString(),
                'status' => strtoupper($record->status), 'note' => $record->catatan,
                'teacher' => $schedule?->guru?->nama_lengkap ?? 'Belum ditentukan',
                'day' => $schedule?->hari, 'start' => $schedule?->jam_mulai,
                'end' => $schedule?->jam_selesai, 'subject' => $schedule?->mapel?->nama];
        })->values()->all();
        return response()->json(['data' => ['context' => $context, 'subjects' => $subjects, 'history' => $rows],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'total' => $page->total(), 'per_page' => $page->perPage()]])->header('Cache-Control', 'private, no-store');
    }
}
