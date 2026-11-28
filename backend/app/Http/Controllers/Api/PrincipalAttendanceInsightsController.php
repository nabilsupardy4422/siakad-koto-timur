<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monitoring presensi akademik untuk Kepala Sekolah.
 *
 * READ-ONLY:
 * - Hanya menghitung catatan presensi yang tersimpan.
 * - Satuan hitung adalah siswa per pertemuan.
 * - Bukan jumlah siswa unik per hari.
 * - Bukan jumlah pertemuan yang direncanakan.
 * - Bukan perhitungan nilai kehadiran rapor.
 */
class PrincipalAttendanceInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Validasi role sebagai lapisan keamanan tambahan.
        abort_unless(
            $request->user()?->role?->code === 'KEPALA_SEKOLAH',
            403
        );

        // Validasi parameter filter.
        abort_if(count(array_diff(array_keys($request->query()), ['academic_year_id','scope','date_from','date_to','class_id']))>0,422,'Filter tidak didukung.');
        $data = $request->validate([
            'academic_year_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'scope' => [
                'required',
                'in:SEMESTER,YEAR',
            ],
            'date_from' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'class_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        // Rentang tanggal harus memiliki kedua batas.
        if (
            isset($data['date_from'])
            xor isset($data['date_to'])
        ) {
            throw ValidationException::withMessages([
                'date_from' =>
                    'Tanggal awal dan akhir harus diisi bersamaan.',
            ]);
        }

        // Ambil tahun akademik yang dipilih.
        $anchor = TahunAkademik::query()
            ->findOrFail($data['academic_year_id']);

        $all = $data['scope'] === 'YEAR';
        foreach (['date_from','date_to'] as $bound) {
            if (isset($data[$bound])) {
                $calendarYear = (int) substr($data[$bound],0,4);
                abort_if($calendarYear < (int)$anchor->tahun_mulai || $calendarYear > (int)$anchor->tahun_selesai,422,'Rentang tanggal di luar tahun kalender periode.');
            }
        }

        // ID kelas terikat pada satu periode/semester.
        // Filter kelas langsung tidak diizinkan untuk
        // gabungan seluruh tahun ajaran.
        if ($all && isset($data['class_id'])) {
            throw ValidationException::withMessages([
                'class_id' =>
                    'Filter kelas hanya berlaku untuk satu semester, '
                    . 'karena ID kelas dapat berubah tiap semester.',
            ]);
        }

        // Tentukan cakupan periode akademik.
        $periods = (
            $all
                ? TahunAkademik::query()
                    ->where(
                        'tahun_mulai',
                        $anchor->tahun_mulai
                    )
                    ->where(
                        'tahun_selesai',
                        $anchor->tahun_selesai
                    )
                    ->get()
                : collect([$anchor])
        )
            ->sortBy(
                fn ($period) =>
                    $period->semester === 'GANJIL'
                        ? 0
                        : 1
            )
            ->values();

        // Jangan menggunakan periode duplikat.
        if (
            $periods->pluck('semester')
                ->unique()
                ->count() !== $periods->count()
        ) {
            return response()->json([
                'message' =>
                    'Periode akademik duplikat. Hubungi Tata Usaha.',
            ], 409);
        }

        // Validasi kepemilikan kelas terhadap periode.
        if (isset($data['class_id'])) {
            $class = Kelas::query()
                ->findOrFail($data['class_id']);

            if (
                (int) $class->tahun_akademik_id
                !== (int) $anchor->id
            ) {
                throw ValidationException::withMessages([
                    'class_id' =>
                        'Kelas berada di periode berbeda.',
                ]);
            }
        }

        // Query hanya membaca data presensi.
        $query = DB::table('presensi as p')
            ->join(
                'jadwal_pelajaran as j',
                'j.id',
                '=',
                'p.jadwal_pelajaran_id'
            )
            ->join(
                'kelas as k',
                'k.id',
                '=',
                'j.kelas_id'
            )
            ->join(
                'mapel as m',
                'm.id',
                '=',
                'j.mapel_id'
            )
            ->join(
                'tahun_akademik as ta',
                'ta.id',
                '=',
                'j.tahun_akademik_id'
            )
            ->whereColumn(
                'k.tahun_akademik_id',
                'j.tahun_akademik_id'
            )
            ->whereIn(
                'j.tahun_akademik_id',
                $periods->pluck('id')->all()
            );
        \App\Services\PrincipalAcademicIntegrity::attendance($query,now('Asia/Jakarta')->toDateString());

        // PERBAIKAN V1.6:
        // Bandingkan bagian tanggal saja,
        // agar catatan pada tanggal akhir tetap terbaca.
        if (isset($data['date_from'])) {
            $query
                ->whereDate(
                    'p.tanggal',
                    '>=',
                    $data['date_from']
                )
                ->whereDate(
                    'p.tanggal',
                    '<=',
                    $data['date_to']
                );
        }

        // Filter kelas hanya untuk periode valid.
        if (isset($data['class_id'])) {
            $query->where('k.id', $data['class_id']);
        }

        // Rekap berdasarkan periode, kelas, dan mapel.
        $rows = $query
            ->select([
                'ta.id as academic_year_id',
                'ta.semester',
                'k.id as class_id',
                'k.nama as class_name',
                'k.tingkat as grade_level',
                'm.id as subject_id',
                'm.nama as subject_name',
            ])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                "SUM(CASE WHEN UPPER(p.status) = 'HADIR' "
                . "THEN 1 ELSE 0 END) as hadir"
            )
            ->selectRaw(
                "SUM(CASE WHEN UPPER(p.status) = 'IZIN' "
                . "THEN 1 ELSE 0 END) as izin"
            )
            ->selectRaw(
                "SUM(CASE WHEN UPPER(p.status) = 'SAKIT' "
                . "THEN 1 ELSE 0 END) as sakit"
            )
            ->selectRaw(
                "SUM(CASE WHEN UPPER(p.status) = 'ALPA' "
                . "THEN 1 ELSE 0 END) as alpa"
            )
            ->selectRaw(
                'COUNT(DISTINCT p.siswa_id) as recorded_students'
            )
            ->groupBy(
                'ta.id',
                'ta.semester',
                'k.id',
                'k.nama',
                'k.tingkat',
                'm.id',
                'm.nama'
            )
            ->orderBy('ta.id')
            ->orderBy('k.tingkat')
            ->orderBy('k.nama')
            ->orderBy('m.nama')
            ->get();

        // Inisialisasi jumlah catatan.
        $totals = [
            'total' => 0,
            'hadir' => 0,
            'izin' => 0,
            'sakit' => 0,
            'alpa' => 0,
        ];

        $classes = [];

        // Susun hasil menjadi rekap kelas -> mapel.
        foreach ($rows as $row) {
            $key = $row->academic_year_id
                . ':'
                . $row->class_id;

            if (!isset($classes[$key])) {
                $classes[$key] = [
                    'academic_year_id' =>
                        (int) $row->academic_year_id,
                    'semester' => $row->semester,
                    'class_id' => (int) $row->class_id,
                    'class_name' => $row->class_name,
                    'grade_level' =>
                        (int) $row->grade_level,
                    'totals' => $totals,
                    'subjects' => [],
                ];
            }

            $subject = [
                'subject_id' => (int) $row->subject_id,
                'subject_name' => $row->subject_name,
                'recorded_students' =>
                    (int) $row->recorded_students,
                'total' => (int) $row->total,
                'hadir' => (int) $row->hadir,
                'izin' => (int) $row->izin,
                'sakit' => (int) $row->sakit,
                'alpa' => (int) $row->alpa,
            ];

            $classes[$key]['subjects'][] = $subject;

            // Akumulasi jumlah kelas dan sekolah.
            foreach (array_keys($totals) as $field) {
                $classes[$key]['totals'][$field]
                    += $subject[$field];

                $totals[$field] += $subject[$field];
            }
        }

        // Respons data monitoring.
        return response()->json([
            'data' => [
                'totals' => $totals,
                'classes' => array_values($classes),
            ],
            'meta' => [
                'scope' => $data['scope'],
                'year' =>
                    $anchor->tahun_mulai
                    . '/'
                    . $anchor->tahun_selesai,
                'academic_year_id' =>
                    (int) $anchor->id,
                'periods' => $periods
                    ->map(fn ($period) => [
                        'id' => (int) $period->id,
                        'semester' => $period->semester,
                    ])
                    ->all(),
                'date_from' =>
                    $data['date_from'] ?? null,
                'date_to' =>
                    $data['date_to'] ?? null,
                'unit' =>
                    'stored_student_meeting_records',
            ],
        ])->header('Cache-Control','private, no-store');
    }
}
