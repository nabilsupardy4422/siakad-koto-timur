<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\HomeroomReadAccess;
use App\Http\Requests\StoreClassRequest;
use App\Http\Requests\UpdateClassRequest;
use App\Http\Resources\ClassResource;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Support\ClassDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'grade_level' => ['nullable', 'integer', 'in:10,11,12'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'directory' => ['nullable', 'boolean'],
            'homeroom' => ['nullable', 'in:assigned,unassigned'],
            'schedule' => ['nullable', 'in:used,unused'],
        ]);
        $user = $request->user();
        $directory = $request->boolean('directory');
        abort_if(($directory || !empty($filters['homeroom']) || !empty($filters['schedule']))
            && !in_array($user->role?->code, ['TU', 'KEPALA_SEKOLAH'], true), 403);
        abort_if(!$directory && (!empty($filters['homeroom']) || !empty($filters['schedule'])), 422, 'Filter administratif membutuhkan direktori periode.');
        $academicYearId = isset($filters['academic_year_id']) ? (int) $filters['academic_year_id'] : null;
        $query = Kelas::query();
        $context = null;
        if ($directory) {
            if ($academicYearId === null) {
                $active = TahunAkademik::where('is_active', true)->pluck('id');
                abort_unless($active->count() === 1, 409, 'Periode aktif tidak tunggal. Pilih periode secara eksplisit.');
                $academicYearId = (int) $active->first();
            }
            $context = ClassDirectory::context(TahunAkademik::findOrFail($academicYearId));
            ClassDirectory::enrich($query, $context);
            if (!empty($filters['homeroom'])) {
                $method = $filters['homeroom'] === 'assigned' ? 'whereHas' : 'whereDoesntHave';
                $query->{$method}('waliKelas', fn ($q) => ClassDirectory::homerooms($q, $context));
            }
            if (!empty($filters['schedule'])) {
                $method = $filters['schedule'] === 'used' ? 'whereHas' : 'whereDoesntHave';
                $query->{$method}('jadwalPelajaran', fn ($q) => ClassDirectory::schedules($q, $context));
            }
        }
        $this->applyViewScope($query, $user, $academicYearId);
        if ($academicYearId !== null) $query->where('tahun_akademik_id', $academicYearId);
        if (isset($filters['grade_level'])) $query->where('tingkat', $filters['grade_level']);
        if (($keyword = trim($filters['search'] ?? '')) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($keyword)).'%';
            $query->whereRaw("LOWER(nama) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if ($directory || isset($filters['sort_order'])) {
            $query->orderByRaw('LOWER(nama) '.(($filters['sort_order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC'))->orderBy('tingkat')->orderBy('id');
        } else $query->latest('id'); // Preserve legacy catalogue ordering.
        $classes = $query->paginate($filters['page_size'] ?? $filters['per_page'] ?? 20)->withQueryString();
        return ApiResponse::collection(ClassResource::collection($classes), [
            'current_page' => $classes->currentPage(), 'per_page' => $classes->perPage(),
            'total' => $classes->total(), 'last_page' => $classes->lastPage(),
            ...($context ? ['context' => $context, 'summary' => ClassDirectory::summary($context)] : []),
        ]);
    }

    public function store(StoreClassRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $class = DB::transaction(function () use ($data) {
                TahunAkademik::whereKey($data['tahun_akademik_id'])->lockForUpdate()->firstOrFail();
                $this->checkName($data);
                return Kelas::create($data);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['nama' => 'Nama rombel sudah digunakan pada tingkat dan periode ini.']);
        }
        return ApiResponse::message('Data kelas berhasil ditambahkan.', new ClassResource($class), 201);
    }

    public function show(Request $request, Kelas $class): JsonResponse
    {
        $academicYearId = $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null;
        abort_unless($this->canViewClass($class, $request->user(), $academicYearId), 403, 'Anda tidak memiliki akses ke resource ini.');
        if ($request->boolean('directory')) {
            abort_unless(in_array($request->user()->role?->code, ['TU', 'KEPALA_SEKOLAH'], true), 403);
            $context = ClassDirectory::context(TahunAkademik::findOrFail($class->tahun_akademik_id));
            $class = ClassDirectory::enrich(Kelas::whereKey($class->id), $context)->firstOrFail();
            // Resolve the resource against this authenticated request, then attach
            // the authoritative context to the detail response, not a model attribute.
            $detail = (new ClassResource($class))->resolve($request);
            $detail['context'] = $context;
            return ApiResponse::success($detail);
        }
        return ApiResponse::success(new ClassResource($class));
    }

    public function update(UpdateClassRequest $request, Kelas $class): JsonResponse
    {
        $data = $request->validated();
        try {
            $class = DB::transaction(function () use ($class, $data) {
                TahunAkademik::whereKey($class->tahun_akademik_id)->lockForUpdate()->firstOrFail();
                $locked = Kelas::whereKey($class->id)->lockForUpdate()->firstOrFail();
                $this->checkVersion($locked, $data['version'] ?? null);
                abort_if((int) $data['tahun_akademik_id'] !== (int) $locked->tahun_akademik_id, 409,
                    'Periode kelas tidak dapat dipindahkan. Buat rombel baru pada periode tujuan.');
                unset($data['version']);
                $this->checkName($data, $locked->id);
                $locked->fill($data);
                if ($locked->isDirty()) $this->assertUnused($locked);
                $locked->save();
                return $locked->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['nama' => 'Nama rombel sudah digunakan pada tingkat dan periode ini.']);
        }
        return ApiResponse::message('Data kelas berhasil diperbarui.', new ClassResource($class));
    }

    public function destroy(Request $request, Kelas $class): JsonResponse
    {
        $data = $request->validate(['version' => ['nullable', 'string', 'size:64']]);
        DB::transaction(function () use ($class, $data) {
            $locked = Kelas::whereKey($class->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $data['version'] ?? null);
            $this->assertUnused($locked);
            $locked->delete();
        }, 3);
        return ApiResponse::message('Data kelas berhasil dihapus.');
    }

    private function assertUnused(Kelas $class): void
    {
        foreach (['anggotaKelas', 'waliKelas', 'jadwalPelajaran'] as $relation) {
            abort_if($class->{$relation}()->lockForUpdate()->first(['id']), 409,
                'Kelas memiliki relasi atau riwayat akademik. Identitas tidak dapat diubah atau dihapus.');
        }
    }

    private function checkVersion(Kelas $class, ?string $version): void
    {
        abort_if($version !== null && !hash_equals($class->directoryVersion(), $version), 409,
            'Data kelas telah berubah. Buka kembali detail kelas.');
    }

    private function checkName(array $data, ?int $except = null): void
    {
        $query = Kelas::where('tahun_akademik_id', $data['tahun_akademik_id'])->where('tingkat', $data['tingkat'])
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($data['nama'])]);
        if ($except !== null) $query->where('id', '!=', $except);
        if ($query->exists()) throw ValidationException::withMessages(['nama' => 'Nama rombel sudah digunakan pada tingkat dan periode ini.']);
    }

    private function applyViewScope(
        Builder $query,
        $user,
        ?int $academicYearId
    ): void {
        $role = $user->role->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $query->whereHas(
                'anggotaKelas',
                function (Builder $builder) use ($user, $academicYearId): void {
                    $builder->whereHas(
                        'siswa',
                        function (Builder $studentQuery) use ($user): void {
                            $studentQuery->where('user_id', $user->id);
                        }
                    );
                }
            );

            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if (! $guruId) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function (Builder $builder) use (
                $guruId,
                $academicYearId
            ): void {
                $builder->whereHas(
                    'jadwalPelajaran',
                    function (Builder $scheduleQuery) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $scheduleQuery->where('guru_id', $guruId);

                        if ($academicYearId !== null) {
                            $scheduleQuery->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
                    }
                );

                $builder->orWhereHas(
                    'waliKelas',
                    function (Builder $waliQuery) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        HomeroomReadAccess::restrictCurrent($waliQuery, (int) $guruId, $academicYearId);
                    }
                );
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewClass(
        Kelas $class,
        $user,
        ?int $academicYearId
    ): bool {
        if ($academicYearId !== null &&
            (int) $class->tahun_akademik_id !== $academicYearId) {
            return false;
        }

        $role = $user->role->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return true;
        }

        if ($role === 'SISWA') {
            return $class->anggotaKelas()
                ->whereHas(
                    'siswa',
                    fn (Builder $query) => $query->where(
                        'user_id',
                        $user->id
                    )
                )
                ->exists();
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if (! $guruId) {
                return false;
            }

            $teachingClass = $class->jadwalPelajaran()
                ->where('guru_id', $guruId)
                ->when(
                    $academicYearId !== null,
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
                ->exists();

            if ($teachingClass) {
                return true;
            }

            return HomeroomReadAccess::currentAssignments((int) $guruId, $academicYearId)
                ->where('kelas_id', $class->id)->exists();
        }

        return false;
    }
}
