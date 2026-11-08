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

        $today = Carbon::today();

        $query = AnggotaKelas::query()
            ->with('siswa')
            ->where('kelas_id', $class->id)
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('tanggal_selesai')
                    ->orWhereDate('tanggal_selesai', '>=', $today);
            });

        /*
         * Siswa hanya dapat melihat keanggotaan dirinya sendiri.
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
             * Lock siswa selama pengecekan dan insert.
             */
            $student = Siswa::query()
                ->lockForUpdate()
                ->find($validated['siswa_id']);

            if (! $student) {
                return response()->json([
                    'message' => 'Siswa tidak ditemukan.',
                ], 404);
            }

            $startDate = Carbon::parse(
                $validated['tanggal_mulai']
            )->startOfDay();

            $today = Carbon::today();

            /*
             * Periksa keanggotaan yang benar-benar berlaku
             * pada tahun akademik kelas tujuan.
             *
             * Keanggotaan yang belum mulai tidak dianggap aktif.
             * Keanggotaan yang sudah selesai sebelum hari ini
             * juga tidak dianggap aktif.
             */
            $existingActiveMembership = AnggotaKelas::query()
                ->where('siswa_id', $student->id)
                ->whereDate('tanggal_mulai', '<=', $today)
                ->where(function (Builder $query) use ($today): void {
                    $query
                        ->whereNull('tanggal_selesai')
                        ->orWhereDate(
                            'tanggal_selesai',
                            '>=',
                            $today
                        );
                })
                ->whereHas('kelas', function (
                    Builder $query
                ) use ($class): void {
                    $query->where(
                        'tahun_akademik_id',
                        $class->tahun_akademik_id
                    );
                })
                ->exists();

            if ($existingActiveMembership) {
                return response()->json([
                    'message' => 'Siswa sudah memiliki kelas aktif pada tahun akademik ini.',
                ], 422);
            }

            return AnggotaKelas::create([
                'kelas_id' => $class->id,
                'siswa_id' => $student->id,
                'tanggal_mulai' => $startDate,
                'tanggal_selesai' => null,
            ]);
        });

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        return ApiResponse::message(
            'Siswa berhasil ditambahkan ke kelas.',
            new ClassMemberResource(
                $membership->load('siswa')
            ),
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

        $today = Carbon::today();

        /*
         * Cari keanggotaan yang benar-benar aktif hari ini.
         */
        $membership = AnggotaKelas::query()
            ->where('kelas_id', $class->id)
            ->where('siswa_id', $student->id)
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('tanggal_selesai')
                    ->orWhereDate(
                        'tanggal_selesai',
                        '>=',
                        $today
                    );
            })
            ->latest('tanggal_mulai')
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'Siswa bukan anggota aktif dari kelas ini.',
            ], 404);
        }

        /*
         * Tanggal selesai tidak boleh lebih awal dari
         * tanggal mulai keanggotaan.
         */
        $startDate = Carbon::parse(
            $membership->tanggal_mulai
        )->startOfDay();

        if ($today->lessThan($startDate)) {
            return response()->json([
                'message' => 'Keanggotaan belum dimulai sehingga tidak dapat diakhiri.',
            ], 422);
        }

        $membership->update([
            'tanggal_selesai' => $today,
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

        if (in_array(
            $role,
            ['TU', 'KEPALA_SEKOLAH'],
            true
        )) {
            return true;
        }

        $today = Carbon::today();

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return false;
            }

            return $class->anggotaKelas()
                ->where('siswa_id', $studentId)
                ->whereDate('tanggal_mulai', '<=', $today)
                ->where(function (
                    Builder $query
                ) use ($today): void {
                    $query
                        ->whereNull('tanggal_selesai')
                        ->orWhereDate(
                            'tanggal_selesai',
                            '>=',
                            $today
                        );
                })
                ->exists();
        }

        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        /*
         * Guru mata pelajaran dapat melihat anggota kelas
         * apabila memiliki jadwal mengajar pada kelas tersebut.
         */
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

        /*
         * Wali kelas hanya mendapat akses melalui assignment
         * yang berlaku pada tanggal hari ini.
         */
        return $this->hasEffectiveHomeroomAssignment(
            $class,
            $guruId,
            $today
        );
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
         * Kepala sekolah dan siswa tidak dapat mengelola
         * anggota kelas melalui endpoint ini.
         */
        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        /*
         * Guru hanya dapat mengelola anggota jika assignment
         * wali kelasnya berlaku hari ini.
         */
        return $this->hasEffectiveHomeroomAssignment(
            $class,
            $guruId,
            Carbon::today()
        );
    }

    private function hasEffectiveHomeroomAssignment(
        Kelas $class,
        int $guruId,
        Carbon $date
    ): bool {
        return WaliKelas::query()
            ->where('guru_id', $guruId)
            ->where('kelas_id', $class->id)
            ->where(
                'tahun_akademik_id',
                $class->tahun_akademik_id
            )
            ->whereDate('tanggal_mulai', '<=', $date)
            ->where(function (
                Builder $query
            ) use ($date): void {
                $query
                    ->whereNull('tanggal_selesai')
                    ->orWhereDate(
                        'tanggal_selesai',
                        '>=',
                        $date
                    );
            })
            ->exists();
    }
}
