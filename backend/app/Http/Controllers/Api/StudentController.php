<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\JadwalPelajaran;
use App\Models\Siswa;
use App\Models\User;
use App\Support\HomeroomReadAccess;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'siswa', 'guru']);

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        $directory = $user->role?->code === 'TU' && $request->boolean('directory_context');
        $period = $directory ? \App\Support\StudentPlacement::period($request) : null;
        $request->attributes->set('student_directory_period', $period);
        $students = Siswa::query()
            ->with('user')
            ->when(! $directory && $user->role?->code !== 'GURU' && ($request->filled('class_id') || $academicYearId !== null),
                function (Builder $query) use ($request, $academicYearId): void {
                    $query->whereHas('anggotaKelas', function (Builder $membership) use ($request, $academicYearId): void {
                        if ($request->filled('class_id')) {
                            $membership->where('kelas_id', $request->integer('class_id'));
                        }
                        if ($academicYearId !== null) {
                            $membership->whereHas('kelas', fn (Builder $class) => $class->where('tahun_akademik_id', $academicYearId));
                        }
                    });
                })
            ->when(
                $request->filled('search'),
                function (Builder $query) use ($request, $user): void {
                    $search = $request->string('search')->toString();

                    $query->where(function (Builder $query) use ($search, $user): void {
                        $query->where(
                            'nisn',
                            'like',
                            "%{$search}%"
                        )
                            ->orWhere('nipd', 'like', "%{$search}%")
                            ->orWhere(
                                'nis',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'nama_lengkap',
                                'like',
                                "%{$search}%"
                            )->when($user->role?->code === 'TU', fn ($q) => $q->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$search}%")));
                    });
                }
            );

        if ($directory) {
            \App\Support\IdentityDirectory::apply($students, $request, true);
            $request->validate(['class_id' => ['sometimes', 'integer', 'exists:kelas,id'], 'placement_state' => ['sometimes', 'in:unassigned']]);
            \App\Support\StudentPlacement::load($students, $period);
            if ($request->filled('class_id') || $request->filled('placement_state')) {
                if ($period === null) {
                    abort(422, 'Periode akademik belum tersedia.');
                }
                $historical = ! \App\Models\TahunAkademik::query()->whereKey($period)->where('is_active', true)->exists();
                $classId = $request->filled('class_id') ? $request->integer('class_id') : null;
                if ($classId !== null && ! \App\Models\Kelas::query()->whereKey($classId)->where('tahun_akademik_id', $period)->exists()) {
                    abort(422, 'Kelas tidak sesuai periode.');
                }
                $membership = fn (Builder $q) => \App\Support\StudentPlacement::scope($q, $period, $classId, ! $historical);
                if ($request->input('placement_state') === 'unassigned') {
                    $students->whereDoesntHave('anggotaKelas', fn (Builder $q) => \App\Support\StudentPlacement::scope($q, $period, null, ! $historical));
                } else {
                    $students->whereHas('anggotaKelas', $membership);
                }
            }
        }

        $this->applyViewScope(
            $students,
            $user,
            $academicYearId,
            $request->filled('class_id') ? $request->integer('class_id') : null
        );

        $students = $students
             ->orderBy('nama_lengkap', $request->input('sort_order') === 'desc' ? 'desc' : 'asc')
    ->orderBy('id', 'asc')
    ->paginate(min(100, max(1, $request->integer('page_size', $request->integer('per_page', 15)))))
    ->withQueryString();

        return ApiResponse::collection(
            StudentResource::collection($students->items()),
            [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ]
        );
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = app(\App\Services\AcademicIdentityService::class)->register('SISWA', $request->validated());

        $student->load('user');

        return ApiResponse::success(
            new StudentResource($student),
            201
        );
    }

    public function show(
        Request $request,
        Siswa $student
    ): JsonResponse {
        $user = $request->user();
        $user->loadMissing(['role', 'siswa', 'guru']);

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        if (! $this->canViewStudent(
            $student,
            $user,
            $academicYearId,
            $request->filled('class_id') ? $request->integer('class_id') : null
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $student->load('user');
        if ($user->role?->code === 'TU' && $request->boolean('directory_context')) {
            $period = \App\Support\StudentPlacement::period($request);
            $request->attributes->set('student_directory_period', $period);
            $query = Siswa::query()->whereKey($student->id)->with('user');
            \App\Support\StudentPlacement::load($query, $period);
            $student = $query->firstOrFail();
        }

        return ApiResponse::success(
            new StudentResource($student)
        );
    }

    public function update(
        UpdateStudentRequest $request,
        Siswa $student
    ): JsonResponse {
        $student = app(\App\Services\AcademicIdentityService::class)->update($student, $request->validated());

        $student->load('user');

        return ApiResponse::success(
            new StudentResource($student)
        );
    }

    public function destroy(Siswa $student): JsonResponse
    {
        app(\App\Services\AcademicIdentityService::class)->remove($student);

        return ApiResponse::message(
            'Data siswa berhasil dihapus.'
        );
    }

    private function applyViewScope(
        Builder $query,
        User $user,
        ?int $academicYearId = null,
        ?int $classId = null
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereKey($studentId);

            return;
        }

        if ($role !== 'GURU') {
            $query->whereRaw('1 = 0');

            return;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        // A single membership must satisfy BOTH the requested context and
        // the authorization scope. Separate whereHas clauses leak membership
        // information when a student belongs to two different classes.
        $teachingClasses = JadwalPelajaran::query()
            ->where('guru_id', $guruId)
            ->whereHas('kelas', fn (Builder $class) => $class->whereColumn('kelas.tahun_akademik_id', 'jadwal_pelajaran.tahun_akademik_id'))
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('tahun_akademik_id', $academicYearId))
            ->pluck('kelas_id');
        $waliClasses = HomeroomReadAccess::currentAssignments((int) $guruId, $academicYearId)->pluck('kelas_id');
        $today = now('Asia/Jakarta')->toDateString();

        $query->whereHas('anggotaKelas', function (Builder $membership) use ($teachingClasses, $waliClasses, $classId, $today): void {
            if ($classId !== null) {
                $membership->where('kelas_id', $classId);
            }
            $membership->where(function (Builder $scope) use ($teachingClasses, $waliClasses, $today): void {
                // Preserve the historical teaching scope. Homeroom-only access
                // to the generic student directory requires effective membership;
                // historical roster remains on the dedicated homeroom endpoint.
                $scope->whereIn('kelas_id', $teachingClasses)
                    ->orWhere(function (Builder $homeroom) use ($waliClasses, $today): void {
                        $homeroom->whereIn('kelas_id', $waliClasses)
                            ->whereDate('tanggal_mulai', '<=', $today)
                            ->where(fn (Builder $end) => $end->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $today));
                    });
            });
        });
    }

    private function canViewStudent(
        Siswa $student,
        User $user,
        ?int $academicYearId = null,
        ?int $classId = null
    ): bool {
        $query = Siswa::query()->whereKey($student->id);
        $this->applyViewScope($query, $user, $academicYearId, $classId);
        return $query->exists();
    }

    public function availableAccounts(): JsonResponse
    {
        $accounts = User::query()
            ->select(['id', 'username', 'name', 'email'])
            ->where('account_status', 'active')
            ->whereHas('role', function (Builder $query): void {
                $query->where('code', 'SISWA');
            })
            ->whereDoesntHave('siswa')->whereDoesntHave('guru')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($accounts);
    }
}
