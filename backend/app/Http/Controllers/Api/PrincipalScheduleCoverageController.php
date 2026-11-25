<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only visibility of saved attendance against WEEKLY schedule patterns.
 * A registered weekly schedule is not proof a class was actually held.
 * This endpoint must never be presented as teacher discipline/compliance.
 */
class PrincipalScheduleCoverageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role?->code === 'KEPALA_SEKOLAH', 403);

        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'class_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $year = TahunAkademik::query()->findOrFail($data['academic_year_id']);
        $date = $data['date'];
        $yearNumber = (int) substr($date, 0, 4);
        if ($yearNumber < (int) $year->tahun_mulai || $yearNumber > (int) $year->tahun_selesai) {
            throw ValidationException::withMessages([
                'date' => 'Tanggal berada di luar tahun kalender yang tercakup dalam tahun ajaran.',
            ]);
        }

        if (isset($data['class_id'])) {
            $class = Kelas::query()->findOrFail($data['class_id']);
            if ((int) $class->tahun_akademik_id !== (int) $year->id) {
                throw ValidationException::withMessages([
                    'class_id' => 'Kelas tidak termasuk periode akademik terpilih.',
                ]);
            }
        }

        $days = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
        $weekday = $days[Carbon::createFromFormat('!Y-m-d', $date, 'Asia/Jakarta')->isoWeekday()];
        $now = Carbon::now('Asia/Jakarta');
        $today = $now->toDateString();
        $clock = $now->format('H:i:s');

        // The timetable is recurring; do not infer holidays, cancellations,
        // substitutions or actual teaching from these stored rows.
        $schedules = DB::table('jadwal_pelajaran as j')
            ->join('kelas as k', 'k.id', '=', 'j.kelas_id')
            ->join('mapel as m', 'm.id', '=', 'j.mapel_id')
            ->join('guru as g', 'g.id', '=', 'j.guru_id')
            ->where('j.tahun_akademik_id', $year->id)
            ->whereColumn('k.tahun_akademik_id', 'j.tahun_akademik_id')
            ->whereRaw('LOWER(j.hari) = ?', [strtolower($weekday)])
            ->when(isset($data['class_id']), fn ($q) => $q->where('j.kelas_id', $data['class_id']))
            ->select([
                'j.id as schedule_id', 'j.kelas_id as class_id',
                'k.nama as class_name', 'k.tingkat as grade_level',
                'm.nama as subject_name', 'g.nama_lengkap as teacher_name',
                'j.jam_mulai', 'j.jam_selesai',
            ])
            ->orderBy('j.jam_mulai')
            ->orderBy('k.tingkat')
            ->orderBy('k.nama')
            ->orderBy('j.id')
            ->get();

        $classIds = $schedules->pluck('class_id')->unique()->values()->all();
        $scheduleIds = $schedules->pluck('schedule_id')->all();
        $members = collect();
        $recorded = collect();

        if ($classIds !== []) {
            $members = DB::table('anggota_kelas as ak')
                ->whereIn('ak.kelas_id', $classIds)
                ->whereDate('ak.tanggal_mulai', '<=', $date)
                ->where(function ($q) use ($date): void {
                    $q->whereNull('ak.tanggal_selesai')
                        ->orWhereDate('ak.tanggal_selesai', '>=', $date);
                })
                ->select('ak.kelas_id')
                ->selectRaw('COUNT(DISTINCT ak.siswa_id) as total_students')
                ->groupBy('ak.kelas_id')
                ->pluck('total_students', 'kelas_id');
        }

        if ($scheduleIds !== []) {
            $recorded = DB::table('presensi as p')
                ->join('jadwal_pelajaran as j', 'j.id', '=', 'p.jadwal_pelajaran_id')
                ->whereIn('p.jadwal_pelajaran_id', $scheduleIds)
                ->whereDate('p.tanggal', $date)
                ->whereIn('p.status', ['HADIR', 'IZIN', 'SAKIT', 'ALPA'])
                ->whereExists(function ($q) use ($date): void {
                    $q->selectRaw('1')
                        ->from('anggota_kelas as ak')
                        ->whereColumn('ak.kelas_id', 'j.kelas_id')
                        ->whereColumn('ak.siswa_id', 'p.siswa_id')
                        ->whereDate('ak.tanggal_mulai', '<=', $date)
                        ->where(function ($end) use ($date): void {
                            $end->whereNull('ak.tanggal_selesai')
                                ->orWhereDate('ak.tanggal_selesai', '>=', $date);
                        });
                })
                ->select('p.jadwal_pelajaran_id')
                ->selectRaw('COUNT(DISTINCT p.siswa_id) as recorded_students')
                ->groupBy('p.jadwal_pelajaran_id')
                ->pluck('recorded_students', 'jadwal_pelajaran_id');
        }

        $summary = [
            'scheduled' => $schedules->count(),
            'with_students' => 0,
            'evaluated' => 0,
            'with_records' => 0,
            'complete' => 0,
            'partial' => 0,
            'without_records' => 0,
            'not_finished' => 0,
            'without_students' => 0,
        ];
        $items = [];
        foreach ($schedules as $row) {
            $students = (int) ($members[$row->class_id] ?? 0);
            $saved = (int) ($recorded[$row->schedule_id] ?? 0);
            if ($saved > $students) {
                return response()->json([
                    'message' => 'Data presensi dan keanggotaan kelas tidak konsisten.',
                ], 409);
            }

            $endTime = substr((string) $row->jam_selesai, 0, 8);
            $startTime = substr((string) $row->jam_mulai, 0, 8);
            $timeState = $date > $today || ($date === $today && $clock < $startTime)
                ? 'upcoming'
                : (($date === $today && $clock < $endTime) ? 'ongoing' : 'finished');

            if ($students === 0) {
                $state = 'without_students';
                $summary['without_students']++;
            } elseif ($timeState !== 'finished') {
                $state = 'not_finished';
                $summary['with_students']++;
                $summary['not_finished']++;
            } else {
                $summary['with_students']++;
                $summary['evaluated']++;
                if ($saved === 0) {
                    $state = 'without_records';
                    $summary['without_records']++;
                } elseif ($saved < $students) {
                    $state = 'partial';
                    $summary['with_records']++;
                    $summary['partial']++;
                } else {
                    $state = 'complete';
                    $summary['with_records']++;
                    $summary['complete']++;
                }
            }
            $items[] = [
                'schedule_id' => (int) $row->schedule_id,
                'class_id' => (int) $row->class_id,
                'class_name' => $row->class_name,
                'grade_level' => (int) $row->grade_level,
                'subject_name' => $row->subject_name,
                'teacher_name' => $row->teacher_name,
                'start_time' => substr($startTime, 0, 5),
                'end_time' => substr($endTime, 0, 5),
                'students' => $students,
                'recorded_students' => $saved,
                'time_state' => $timeState,
                'state' => $state,
            ];
        }

        return response()->json([
            'data' => ['summary' => $summary, 'schedules' => $items],
            'meta' => [
                'academic_year_id' => (int) $year->id,
                'date' => $date,
                'weekday' => $weekday,
                'class_id' => isset($data['class_id']) ? (int) $data['class_id'] : null,
                'unit' => 'weekly_schedule_with_saved_student_records',
                'is_actual_lesson_confirmation' => false,
            ],
        ]);
    }
}
