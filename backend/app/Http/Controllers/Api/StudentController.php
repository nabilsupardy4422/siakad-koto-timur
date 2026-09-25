<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\JadwalPelajaran;
use App\Models\Siswa;
use App\Models\User;
use App\Models\WaliKelas;
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

        $students = Siswa::query()
            ->with('user')
            ->when(
                $request->filled('class_id'),
                function (Builder $query) use ($request): void {
                    $classId = $request->integer('class_id');

                    $query->whereHas(
                        'anggotaKelas',
                        function (Builder $query) use ($classId): void {
                            $query->where('kelas_id', $classId);
                        }
                    );
                }
            )
            ->when(
                $academicYearId !== null,
                function (Builder $query) use ($academicYearId): void {
                    $query->whereHas(
                        'anggotaKelas.kelas',
                        function (Builder $query) use ($academicYearId): void {
                            $query->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
                    );
                }
            )
            ->when(
                $request->filled('search'),
                function (Builder $query) use ($request): void {
                    $search = $request->string('search')->toString();

                    $query->where(function (Builder $query) use ($search): void {
                        $query->where(
                            'nisn',
                            'like',
                            "%{$search}%"
                        )
                            ->orWhere(
                                'nis',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'nama_lengkap',
                                'like',
                                "%{$search}%"
                            );
                    });
                }
            );

        $this->applyViewScope(
            $students,
            $user,
            $academicYearId
        );

        $students = $students
            ->latest('id')
            ->paginate($request->integer('page_size', 15))
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
        $student = Siswa::create($request->validated());

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
            $academicYearId
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $student->load('user');

        return ApiResponse::success(
            new StudentResource($student)
        );
    }

    public function update(
        UpdateStudentRequest $request,
        Siswa $student
    ): JsonResponse {
        $student->update($request->validated());

        $student->load('user');

        return ApiResponse::success(
            new StudentResource($student)
        );
    }

    public function destroy(Siswa $student): JsonResponse
    {
        $student->delete();

        return ApiResponse::message(
            'Data siswa berhasil dihapus.'
        );
    }

    private function applyViewScope(
        Builder $query,
        User $user,
        ?int $academicYearId = null
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

        $teachingClasses = JadwalPelajaran::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                function (Builder $query) use ($academicYearId): void {
                    $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    );
                }
            )
            ->pluck('kelas_id');

        $waliClasses = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                function (Builder $query) use ($academicYearId): void {
                    $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    );
                }
            )
            ->pluck('kelas_id');

        $classIds = $teachingClasses
            ->merge($waliClasses)
            ->unique()
            ->values();

        if ($classIds->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas(
            'anggotaKelas',
            function (Builder $query) use ($classIds): void {
                $query->whereIn('kelas_id', $classIds);
            }
        );
    }

    private function canViewStudent(
        Siswa $student,
        User $user,
        ?int $academicYearId = null
    ): bool {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return true;
        }

        if ($role === 'SISWA') {
            return $user->siswa?->id === $student->id;
        }

        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        $teachingClasses = JadwalPelajaran::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                function (Builder $query) use ($academicYearId): void {
                    $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    );
                }
            )
            ->pluck('kelas_id');

        $waliClasses = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                function (Builder $query) use ($academicYearId): void {
                    $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    );
                }
            )
            ->pluck('kelas_id');

        $classIds = $teachingClasses
            ->merge($waliClasses)
            ->unique()
            ->values();

        if ($classIds->isEmpty()) {
            return false;
        }

        return $student->anggotaKelas()
            ->whereIn('kelas_id', $classIds)
            ->exists();
    }
}