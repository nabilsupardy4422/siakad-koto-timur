<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Services\ClassMembershipService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class ClassMemberBulkController extends Controller
{
    private function validateInput(Request $request, bool $confirm): array
    {
        abort_unless($request->user()?->role?->code === 'TU', 403);
        return $request->validate([
            'siswa_ids' => ['required', 'array', 'min:1', 'max:100'],
            'siswa_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'preview_token' => $confirm ? ['required', 'string', 'max:100'] : ['nullable'],
        ]);
    }

    /** Read-only candidate list. Use the same open-interval rules as placement preview. */
    public function candidates(Request $request, Kelas $class)
    {
        abort_unless($request->user()?->role?->code === 'TU', 403);
        $data = $request->validate([
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $start = $data['tanggal_mulai'];
        $search = trim($data['search'] ?? '');
        $students = Siswa::query()
            ->whereDoesntHave('academicMembershipEvents', fn ($q) => $q->whereIn('action', ['graduate', 'withdraw']))
            ->whereDoesntHave('anggotaKelas', function ($q) use ($class, $start) {
                $q->whereHas('kelas', fn ($c) => $c->where('tahun_akademik_id', $class->tahun_akademik_id))
                    ->where(function ($interval) use ($start) {
                        $interval->whereNull('tanggal_mulai')
                            ->orWhereNull('tanggal_selesai')
                            ->orWhereDate('tanggal_selesai', '>=', $start)
                            ->orWhereRaw('DATE(tanggal_selesai) < DATE(tanggal_mulai)');
                    });
            })
            ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
                ->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('nisn', 'like', "%{$search}%")
                ->orWhere('nipd', 'like', "%{$search}%")))
            ->orderBy('nama_lengkap')->orderBy('id')
            ->paginate($data['per_page'] ?? 15, ['id', 'nama_lengkap', 'nisn', 'nipd']);

        return ApiResponse::collection($students->items(), [
            'current_page' => $students->currentPage(),
            'last_page' => $students->lastPage(),
            'per_page' => $students->perPage(),
            'total' => $students->total(),
        ]);
    }

    public function preview(Request $request, Kelas $class, ClassMembershipService $service)
    {
        $data = $this->validateInput($request, false);
        $snapshot = $service->snapshot($class, $data['siswa_ids'], $data['tanggal_mulai']);
        return ApiResponse::success(['rows' => $snapshot['rows'], 'preview_token' => $service->issue($snapshot, (int)$request->user()->id, 'bulk')]);
    }

    public function store(Request $request, Kelas $class, ClassMembershipService $service)
    {
        $data = $this->validateInput($request, true);
        $members = $service->place($class, $data['siswa_ids'], $data['tanggal_mulai'], (int)$request->user()->id, $data['preview_token']);
        return ApiResponse::message('Penempatan massal berhasil.', ['total_imported' => count($members)], 201);
    }
}
