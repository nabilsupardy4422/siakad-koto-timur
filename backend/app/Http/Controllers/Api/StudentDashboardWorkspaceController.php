<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\BahanAjar;
use App\Models\JadwalPelajaran;
use App\Models\SubmissionTugas;
use App\Models\TahunAkademik;
use App\Support\StudentMaterialAccess;
use App\Support\StudentScheduleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Read-only, server-scoped home screen for the authenticated student. */
final class StudentDashboardWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->loadMissing(['role', 'siswa']);
        if ($user?->role?->code !== 'SISWA' || ! $user->siswa) {
            return response()->json(['message' => 'Dashboard hanya untuk siswa.'], 403);
        }
        // A caller may not override class, student, date, or academic period.
        $request->validate([
            'student_id' => ['prohibited'], 'siswa_id' => ['prohibited'],
            'class_id' => ['prohibited'], 'academic_year_id' => ['prohibited'],
            'as_of' => ['prohibited'],
        ]);

        $years = TahunAkademik::query()->where('is_active', true)->get();
        if ($years->count() !== 1) {
            return response()->json(['message' => 'Periode akademik aktif tidak tunggal.'], 409);
        }
        $year = $years->first();
        $now = Carbon::now('Asia/Jakarta');
        $today = $now->toDateString();
        $dayNames = [0 => 'MINGGU', 1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS', 5 => 'JUMAT', 6 => 'SABTU'];
        $day = $dayNames[$now->dayOfWeek];
        $context = StudentScheduleAccess::resolve((int) $user->siswa->id, $year);
        if ($context['status'] === 'ambiguous') {
            return response()->json(['message' => 'Penempatan kelas aktif ambigu. Hubungi Tata Usaha.'], 409);
        }

        $base = [
            'academic_year' => [
                'id' => (int) $year->id,
                'label' => $year->tahun_mulai.'/'.$year->tahun_selesai.' · '.ucfirst(strtolower($year->semester)),
            ],
            'as_of' => $today,
            'today_name' => $day,
            'student_name' => $user->name,
            'placement_status' => $context['status'],
            'class' => $context['class'],
            'today_schedule' => [],
            'assignment_summary' => ['total' => 0, 'pending' => 0, 'submitted' => 0, 'overdue' => 0, 'late_submitted' => 0],
            'upcoming_assignments' => [],
            'subjects' => [],
            'recent_materials' => [],
        ];
        if ($context['status'] !== 'active') {
            return response()->json(['data' => $base])->header('Cache-Control', 'private, no-store');
        }
        $classId = (int) $context['class']['id'];
        $schedules = JadwalPelajaran::query()
            ->where('tahun_akademik_id', $year->id)
            ->where('kelas_id', $classId)
            ->with(['mapel:id,nama', 'guru:id,nama_lengkap'])
            ->orderBy('jam_mulai')->orderBy('id')->get();
        $scheduleIds = $schedules->pluck('id')->all();
        $base['today_schedule'] = $schedules->where('hari', $day)->map(fn ($s) => [
            'id' => (int) $s->id,
            'subject' => $s->mapel?->nama ?? 'Mata pelajaran',
            'teacher' => $s->guru?->nama_lengkap,
            'start' => substr((string) $s->jam_mulai, 0, 5),
            'end' => substr((string) $s->jam_selesai, 0, 5),
        ])->values()->all();

        $assignments = Assignment::query()
            ->whereIn('jadwal_pelajaran_id', $scheduleIds)
            ->with('jadwalPelajaran.mapel:id,nama')
            ->orderBy('id')->get();
        $submissions = SubmissionTugas::query()->where('siswa_id', $user->siswa->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get()->keyBy('assignment_id');
        $summary = $base['assignment_summary'];
        $rows = [];
        $subjectStats = [];
        foreach ($schedules as $schedule) {
            $subjectId = (int) $schedule->mapel_id;
            if (! isset($subjectStats[$subjectId])) {
                $subjectStats[$subjectId] = [
                    'id' => $subjectId,
                    'name' => $schedule->mapel?->nama ?? 'Mata pelajaran',
                    'teachers' => [], 'total' => 0, 'pending' => 0,
                ];
            }
            $teacher = $schedule->guru?->nama_lengkap;
            if ($teacher && ! in_array($teacher, $subjectStats[$subjectId]['teachers'], true)) {
                $subjectStats[$subjectId]['teachers'][] = $teacher;
            }
        }
        foreach ($assignments as $assignment) {
            $sub = $submissions->get($assignment->id);
            $submitted = $sub !== null;
            $overdue = ! $submitted && $assignment->deadline !== null && $assignment->deadline->lessThan($now);
            $lateSubmitted = $submitted && strtolower((string) $sub->status) === 'terlambat';
            $summary['total']++;
            $summary[$submitted ? 'submitted' : 'pending']++;
            if ($overdue) $summary['overdue']++;
            if ($lateSubmitted) $summary['late_submitted']++;
            $subjectId = (int) $assignment->jadwalPelajaran?->mapel_id;
            if (isset($subjectStats[$subjectId])) {
                $subjectStats[$subjectId]['total']++;
                if (! $submitted) $subjectStats[$subjectId]['pending']++;
            }
            if (! $submitted) {
                $rows[] = [
                    'id' => (int) $assignment->id,
                    'title' => $assignment->judul,
                    'subject' => $assignment->jadwalPelajaran?->mapel?->nama ?? 'Mata pelajaran',
                    'deadline' => $assignment->deadline?->toISOString(),
                    'status' => $overdue ? 'overdue' : 'pending',
                ];
            }
        }
        // Unsubmitted overdue tasks first, followed by the nearest deadline;
        // tasks without a deadline come last. No arbitrary count is substituted.
        usort($rows, function ($a, $b): int {
            if (($a['status'] === 'overdue') !== ($b['status'] === 'overdue')) {
                return $a['status'] === 'overdue' ? -1 : 1;
            }
            return strcmp($a['deadline'] ?? '9999', $b['deadline'] ?? '9999') ?: ($a['id'] <=> $b['id']);
        });
        $base['assignment_summary'] = $summary;
        $base['upcoming_assignments'] = array_slice($rows, 0, 5);
        $base['subjects'] = array_values($subjectStats);

        // Match the authorization policy of the student materials module.
        $materials = BahanAjar::query()->whereIn('jadwal_pelajaran_id', $scheduleIds);
        StudentMaterialAccess::apply($materials, (int) $user->siswa->id);
        $base['recent_materials'] = $materials
            ->with('jadwalPelajaran.mapel:id,nama')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(4)->get()
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'title' => $m->judul,
                'subject' => $m->jadwalPelajaran?->mapel?->nama ?? 'Mata pelajaran',
                'created_at' => $m->created_at?->toISOString(),
            ])->values()->all();
        return response()->json(['data' => $base])->header('Cache-Control', 'private, no-store');
    }
}
