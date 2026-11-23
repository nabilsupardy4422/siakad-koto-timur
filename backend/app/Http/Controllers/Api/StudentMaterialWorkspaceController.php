<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BahanAjar;
use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use App\Support\StudentMaterialAccess;
use App\Support\StudentScheduleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Server-scoped student materials browser; never accepts a student identity. */
final class StudentMaterialWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'academic_year_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'subject_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'required', 'in:newest,oldest'],
        ]);
        $user = $request->user();
        $user->loadMissing(['role', 'siswa']);
        abort_unless($user->role?->code === 'SISWA', 403);
        $studentId = $user->siswa?->id;
        abort_unless($studentId !== null, 403);

        $year = isset($input['academic_year_id'])
            ? TahunAkademik::findOrFail($input['academic_year_id'])
            : TahunAkademik::where('is_active', true)->first();
        if (!$year) {
            return response()->json(['message' => 'Periode akademik tidak tersedia.'], 409);
        }
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $context = StudentScheduleAccess::resolve((int) $studentId, $year);
        if ($context['status'] === 'ambiguous') {
            return response()->json(['message' => 'Penempatan kelas siswa tidak tunggal. Hubungi TU.'], 409);
        }

        // Current active placement has one authoritative class. An ended
        // placement may still read materials CREATED within its own interval;
        // future-only placements never grant access. Actual file entitlement
        // remains enforced by StudentMaterialAccess on every request.
        $isActive = (bool) $year->is_active;
        if ($isActive && $context['status'] === 'active') {
            $classIds = [(int) $context['class']['id']];
        } else {
            $classIds = \App\Models\AnggotaKelas::query()
                ->where('siswa_id', $studentId)
                ->whereNotNull('tanggal_mulai')
                ->whereDate('tanggal_mulai', '<=', $today)
                ->whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $year->id))
                ->pluck('kelas_id')->unique()->map(fn ($id) => (int) $id)->all();
        }

        $schedules = JadwalPelajaran::query()
            ->with(['mapel:id,nama', 'guru:id,nama_lengkap'])
            ->where('tahun_akademik_id', $year->id)
            ->whereIn('kelas_id', $classIds)
            ->orderBy('mapel_id')->orderBy('id')->get();
        $subjectIds = $schedules->pluck('mapel_id')->unique()->values()->all();

        $base = BahanAjar::query()
            ->whereHas('jadwalPelajaran', fn ($q) => $q
                ->where('tahun_akademik_id', $year->id)
                ->whereIn('kelas_id', $classIds));
        StudentMaterialAccess::apply($base, (int) $studentId);

        $counts = (clone $base)->select('jadwal_pelajaran_id')
            ->selectRaw('COUNT(*) as total')->groupBy('jadwal_pelajaran_id')
            ->pluck('total', 'jadwal_pelajaran_id');
        $cards = $schedules->groupBy('mapel_id')->map(function ($group, $subjectId) use ($counts) {
            return [
                'id' => (int) $subjectId,
                'name' => $group->first()->mapel?->nama ?? 'Mata pelajaran',
                'teachers' => $group->pluck('guru.nama_lengkap')->filter()->unique()->values()->all(),
                'schedule_count' => $group->count(),
                'material_count' => $group->sum(fn ($s) => $counts->get($s->id, 0)),
            ];
        })->values()->all();

        $selectedId = isset($input['subject_id']) ? (int) $input['subject_id'] : null;
        if ($selectedId !== null && !in_array($selectedId, $subjectIds, true)) {
            return response()->json(['message' => 'Mata pelajaran tidak termasuk jadwal siswa.'], 422);
        }
        $items = null;
        if ($selectedId !== null) {
            $query = clone $base;
            $query->whereHas('jadwalPelajaran', fn ($q) => $q->where('mapel_id', $selectedId));
            $search = trim($input['search'] ?? '');
            if ($search !== '') {
                $query->where(function ($q) use ($search): void {
                    $q->where('judul', 'like', '%'.$search.'%')
                      ->orWhereHas('lampiran', fn ($a) => $a->where('file_name', 'like', '%'.$search.'%'))
                      ->orWhere('file_name', 'like', '%'.$search.'%');
                });
            }
            $query->with(['jadwalPelajaran.guru:id,nama_lengkap', 'lampiran']);
            $query->orderBy('created_at', ($input['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->orderBy('id', 'desc');
            $page = $query->paginate($input['per_page'] ?? 10);
            $items = [
                'data' => $page->getCollection()->map(function ($m) {
                    $files = $m->lampiran->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->file_name,
                        'mime_type' => $a->file_mime_type,
                        'size' => $a->file_size,
                    ])->values()->all();
                    if (!$files && $m->file_path) {
                        $files[] = [
                            'id' => null,
                            'name' => $m->file_name,
                            'mime_type' => $m->file_mime_type,
                            'size' => $m->file_size,
                        ];
                    }
                    return [
                        'id' => $m->id,
                        'title' => $m->judul,
                        'description' => $m->deskripsi,
                        'created_at' => $m->created_at?->toISOString(),
                        'teacher' => $m->jadwalPelajaran?->guru?->nama_lengkap,
                        'files' => $files,
                    ];
                })->values()->all(),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ];
        }
        return response()->json([
            'data' => [
                'academic_year' => [
                    'id' => $year->id,
                    'label' => $year->tahun_mulai.'/'.$year->tahun_selesai.' · '.ucfirst(strtolower($year->semester)),
                    'is_active' => $isActive,
                ],
                'class' => $isActive ? $context['class'] : null,
                'placement_status' => $context['status'],
                'subjects' => $cards,
                'materials' => $items,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
