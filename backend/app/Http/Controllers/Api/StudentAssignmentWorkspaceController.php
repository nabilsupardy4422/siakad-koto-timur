<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\AnggotaKelas;
use App\Models\Assignment;
use App\Models\SubmissionTugas;
use App\Support\AssignmentTeachingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

/** Read-only view for the authenticated student's current academic period. */
final class StudentAssignmentWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->loadMissing(['role', 'siswa']);
        if ($user?->role?->code !== 'SISWA' || ! $user->siswa) {
            return response()->json(['message' => 'Akses khusus siswa.'], 403);
        }

        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 50);
        $yearId = AssignmentTeachingAccess::activeYearId();
        if ($yearId === null) {
            return response()->json(['message' => 'Periode akademik aktif tidak tunggal.'], 409);
        }

        $today = now('Asia/Jakarta')->toDateString();
        $memberships = AnggotaKelas::query()
            ->where('siswa_id', $user->siswa->id)
            ->whereDate('tanggal_mulai', '<=', $today)
            ->whereNull('tanggal_selesai')
            ->whereHas('kelas', fn (Builder $q) => $q->where('tahun_akademik_id', $yearId))
            ->with('kelas')
            ->get();

        if ($memberships->count() > 1) {
            return response()->json(['message' => 'Penempatan kelas aktif ambigu.'], 409);
        }

        $membership = $memberships->first();
        $query = Assignment::query()
            ->with(['jadwalPelajaran.kelas', 'jadwalPelajaran.mapel', 'jadwalPelajaran.guru', 'komponenNilai', 'lampiran'])
            ->whereHas('jadwalPelajaran', function (Builder $q) use ($yearId, $membership): void {
                $q->where('tahun_akademik_id', $yearId);
                if ($membership) {
                    $q->where('kelas_id', $membership->kelas_id);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('deadline')->orderByDesc('id');

        $items = $query->paginate($perPage, ['*'], 'page', $page);
        $ids = $items->getCollection()->pluck('id')->all();
        $submissions = SubmissionTugas::query()
            ->with('lampiran')
            ->where('siswa_id', $user->siswa->id)
            ->whereIn('assignment_id', $ids)
            ->get()
            ->keyBy('assignment_id');

        $assignmentData = $items->getCollection()
            ->map(function ($item) use ($request): array {
                $row = (new AssignmentResource($item))->resolve($request);
                $row['guru_pengampu'] = $item->jadwalPelajaran?->guru?->nama_lengkap;
                return $row;
            })
            ->values()->all();
        $submissionData = $submissions->mapWithKeys(fn ($item, $key) => [
            (string) $key => (new SubmissionResource($item))->resolve($request),
        ])->all();

        return response()->json([
            'data' => [
                'academic_year_id' => $yearId,
                'class' => $membership ? [
                    'id' => $membership->kelas_id,
                    'name' => $membership->kelas?->nama,
                ] : null,
                'assignments' => $assignmentData,
                'submissions' => (object) $submissionData,
            ],
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
