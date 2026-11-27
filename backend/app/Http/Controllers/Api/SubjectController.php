<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Mapel;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'usage' => ['nullable', 'in:used,unused'],
            'kkm_state' => ['nullable', 'in:set,unset,invalid'],
        ]);
        $admin = $request->user()->role?->code === 'TU';
        abort_if(!$admin && !empty($filters['usage']), 403, 'Filter penggunaan hanya tersedia untuk Tata Usaha.');
        $query = Mapel::query();
        if ($admin) {
            $query->withCount('jadwalPelajaran');
        }
        if (($keyword = trim($filters['search'] ?? '')) !== '') {
            // Escape LIKE metacharacters so search is a literal substring on both MySQL and SQLite.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($keyword)).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(kode) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(nama) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (($filters['usage'] ?? '') === 'used') {
            $query->has('jadwalPelajaran');
        } elseif (($filters['usage'] ?? '') === 'unused') {
            $query->doesntHave('jadwalPelajaran');
        }
        match ($filters['kkm_state'] ?? '') {
            'set' => $query->whereBetween('kkm', [0, 100]),
            'unset' => $query->whereNull('kkm'),
            'invalid' => $query->where(fn ($q) => $q->where('kkm', '<', 0)->orWhere('kkm', '>', 100)),
            default => null,
        };
        $subjects = $query->orderByRaw('LOWER(nama) '.(($filters['sort_order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC'))
            ->orderBy('id')->paginate($filters['page_size'] ?? $filters['per_page'] ?? 20)->withQueryString();
        return ApiResponse::collection(SubjectResource::collection($subjects), [
            'current_page' => $subjects->currentPage(),
            'per_page' => $subjects->perPage(),
            'total' => $subjects->total(),
            'last_page' => $subjects->lastPage(),
            ...($admin ? ['summary' => [
                'total' => Mapel::count(),
                'used' => Mapel::has('jadwalPelajaran')->count(),
                'unset' => Mapel::whereNull('kkm')->count(),
                'invalid' => Mapel::where('kkm', '<', 0)->orWhere('kkm', '>', 100)->count(),
            ]] : []),
        ]);
    }

    private function checkCode(string $code, ?int $except = null): void
    {
        $query = Mapel::whereRaw('LOWER(kode) = ?', [mb_strtolower($code)]);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['kode' => 'Kode mata pelajaran sudah digunakan.']);
        }
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = $request->validated();
        // New codes are canonical uppercase; existing codes are not rewritten automatically.
        $data['kode'] = strtoupper($data['kode']);
        $this->checkCode($data['kode']);
        try {
            $subject = Mapel::create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['kode' => 'Kode mata pelajaran sudah digunakan.']);
        }
        return ApiResponse::message('Data mata pelajaran berhasil ditambahkan.', new SubjectResource($subject->refresh()->loadCount('jadwalPelajaran')), 201);
    }

    public function show(Request $request, Mapel $subject): JsonResponse
    {
        if ($request->user()->role?->code === 'TU') {
            $subject->loadCount('jadwalPelajaran');
            $subject->setAttribute('usage_context', [
                'periods' => $subject->jadwalPelajaran()->distinct()->count('tahun_akademik_id'),
                'classes' => $subject->jadwalPelajaran()->distinct()->count('kelas_id'),
                'teachers' => $subject->jadwalPelajaran()->distinct()->count('guru_id'),
            ]);
        }
        return ApiResponse::success(new SubjectResource($subject));
    }

    public function update(UpdateSubjectRequest $request, Mapel $subject): JsonResponse
    {
        $data = $request->validated();
        try {
            $subject = DB::transaction(function () use ($subject, $data) {
                $locked = Mapel::whereKey($subject->id)->lockForUpdate()->firstOrFail();
                $this->checkVersion($locked, $data['version'] ?? null);
                unset($data['version']);
                if ($data['kode'] !== $locked->kode) {
                    $data['kode'] = strtoupper($data['kode']);
                }
                $this->checkCode($data['kode'], $locked->id);
                $locked->fill($data);
                if ($locked->isDirty() && $locked->jadwalPelajaran()->lockForUpdate()->first(['id'])) {
                    abort(409, 'Mata pelajaran sudah digunakan dalam jadwal. Kode, nama dan KKM tidak dapat diubah untuk menjaga riwayat akademik.');
                }
                $locked->save();
                return $locked->refresh()->loadCount('jadwalPelajaran');
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['kode' => 'Kode mata pelajaran sudah digunakan.']);
        }
        return ApiResponse::message('Data mata pelajaran berhasil diperbarui.', new SubjectResource($subject));
    }

    public function destroy(Request $request, Mapel $subject): JsonResponse
    {
        $data = $request->validate(['version' => ['nullable', 'string', 'size:64']]);
        DB::transaction(function () use ($subject, $data) {
            $locked = Mapel::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $data['version'] ?? null);
            abort_if($locked->jadwalPelajaran()->lockForUpdate()->first(['id']), 409,
                'Mata pelajaran sudah digunakan dalam jadwal dan tidak dapat dihapus.');
            $locked->delete();
        }, 3);
        return ApiResponse::message('Data mata pelajaran berhasil dihapus.');
    }

    private function checkVersion(Mapel $subject, ?string $version): void
    {
        abort_if($version !== null && !hash_equals($subject->directoryVersion(), $version), 409,
            'Data mata pelajaran telah berubah. Buka kembali detail sebelum melanjutkan.');
    }
}
