<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassMemberRequest;
use App\Http\Resources\ClassMemberResource;
use App\Models\AnggotaKelas;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\User;
use App\Models\WaliKelas;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClassMemberController extends Controller
{
    public function index(
        Request $request,
        Kelas $class
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'siswa', 'guru']);

        if (! $this->canViewClass($class, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $query = AnggotaKelas::query()
            ->with('siswa')
            ->where('kelas_id', $class->id)
            ->whereNull('tanggal_selesai');

        /*
         * Siswa hanya boleh melihat keanggotaan dirinya sendiri.
         * Role lain yang lolos scope class dapat melihat anggota
         * aktif pada kelas tersebut.
         */
        if ($user->role?->code === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return response()->json([
                    'message' => 'Data siswa tidak ditemukan.',
                ], 403);
            }

            $query->where('siswa_id', $studentId);
        }

        $members = $query
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return ApiResponse::collection(
            ClassMemberResource::collection($members->items()),
            [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
            ]
        );
    }

    public function store(
        StoreClassMemberRequest $request,
        Kelas $class
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canManageClassMembers($class, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah anggota kelas ini.',
            ], 403);
        }

        $validated = $request->validated();

        $membership = DB::transaction(function () use (
            $validated,
            $class
        ): AnggotaKelas|JsonResponse {
            /*
             * Lock siswa selama proses pengecekan + insert agar dua
             * request bersamaan tidak membuat dua kelas aktif.
             */
            $student = Siswa::query()
                ->lockForUpdate()
                ->find($validated['siswa_id']);

            if (! $student) {
                return response()->json([
                    'message' => 'Siswa tidak ditemukan.',
                ], 404);
            }

            $existingActiveMembership = AnggotaKelas::query()
                ->where('siswa_id', $student->id)
                ->whereHas('kelas', function (Builder $query) use ($class): void {
                    $query->where(
                        'tahun_akademik_id',
                        $class->tahun_akademik_id
                    );
                })
                ->whereNull('tanggal_selesai')
                ->exists();

            if ($existingActiveMembership) {
                return response()->json([
                    'message' => 'Siswa sudah memiliki kelas aktif pada tahun akademik ini.',
                ], 422);
            }

            return AnggotaKelas::create([
                'kelas_id' => $class->id,
                'siswa_id' => $student->id,
                'tanggal_mulai' => Carbon::parse($validated['tanggal_mulai']),
                'tanggal_selesai' => null,
            ]);
        });

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        return ApiResponse::message(
            'Siswa berhasil ditambahkan ke kelas.',
            new ClassMemberResource($membership->load('siswa')),
            201
        );
    }

    public function destroy(
        Request $request,
        Kelas $class,
        Siswa $student
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canManageClassMembers($class, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah anggota kelas ini.',
            ], 403);
        }

        $membership = AnggotaKelas::query()
            ->where('kelas_id', $class->id)
            ->where('siswa_id', $student->id)
            ->whereNull('tanggal_selesai')
            ->latest('tanggal_mulai')
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'Siswa bukan anggota aktif dari kelas ini.',
            ], 404);
        }

        $membership->update([
            'tanggal_selesai' => Carbon::today(),
        ]);

        return ApiResponse::message(
            'Keanggotaan siswa pada kelas berhasil diakhiri.'
        );
    }

    private function canViewClass(
        Kelas $class,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return true;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return false;
            }

            return $class->anggotaKelas()
                ->where('siswa_id', $studentId)
                ->whereNull('tanggal_selesai')
                ->exists();
        }

        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        $teachingClass = $class->jadwalPelajaran()
            ->where('guru_id', $guruId)
            ->where(
                'tahun_akademik_id',
                $class->tahun_akademik_id
            )
            ->exists();

        if ($teachingClass) {
            return true;
        }

        return $class->waliKelas()
            ->where('guru_id', $guruId)
            ->where(
                'tahun_akademik_id',
                $class->tahun_akademik_id
            )
            ->exists();
    }

    private function canManageClassMembers(
        Kelas $class,
        User $user
    ): bool {
        $role = $user->role?->code;

        if ($role === 'TU') {
            return true;
        }

        /*
         * API specification menyebut Wali Kelas dapat melakukan
         * operasi pada kelas yang ditugaskan kepadanya.
         * Wali Kelas tetap merupakan assignment Guru, bukan role.
         */
        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        return WaliKelas::query()
            ->where('guru_id', $guruId)
            ->where('kelas_id', $class->id)
            ->where(
                'tahun_akademik_id',
                $class->tahun_akademik_id
            )
            ->exists();
    }
}