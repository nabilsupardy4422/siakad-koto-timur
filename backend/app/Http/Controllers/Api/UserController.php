<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    // ==========================================
    // DAFTAR PENGGUNA
    // ==========================================

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('role')
            ->when(
                $request->filled('search'),
                function (Builder $query) use ($request): void {
                    $search = $request
                        ->string('search')
                        ->toString();

                    $query->where(
                        function (Builder $query) use ($search): void {
                            $query
                                ->where(
                                    'username',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )
            ->when(
                $request->filled('role_id'),
                fn (Builder $query) => $query->where(
                    'role_id',
                    $request->integer('role_id')
                )
            )
            ->when(
                $request->filled('account_status'),
                fn (Builder $query) => $query->where(
                    'account_status',
                    $request
                        ->string('account_status')
                        ->toString()
                )
            )
            ->latest('id')
            ->paginate(
                min(
                    max($request->integer('page_size', 15), 1),
                    100
                )
            )
            ->withQueryString();

        return ApiResponse::collection(
            UserResource::collection($users->items()),
            [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        );
    }

    // ==========================================
    // TAMBAH PENGGUNA
    // ==========================================

    public function store(
        StoreUserRequest $request
    ): JsonResponse {
        $user = User::create([
            ...$request->safe()->only([
                'username',
                'name',
                'email',
                'role_id',
                'password',
            ]),
            'account_status' => 'active',
        ]);

        $user->load('role');

        return ApiResponse::success(
            new UserResource($user),
            201
        );
    }

    // ==========================================
    // DETAIL PENGGUNA
    // ==========================================

    public function show(User $user): JsonResponse
    {
        $user->load('role');

        return ApiResponse::success(
            new UserResource($user)
        );
    }

    // ==========================================
    // EDIT PENGGUNA
    // ==========================================

    public function update(
        UpdateUserRequest $request,
        User $user
    ): JsonResponse {
        $data = $request->safe()->only([
            'username',
            'name',
            'email',
            'role_id',
        ]);

        return DB::transaction(
            function () use ($user, $data): JsonResponse {

                // Kunci daftar TU aktif untuk
                // melindungi administrator terakhir.
                $activeTuUsers = User::query()
                    ->where(
                        'account_status',
                        'active'
                    )
                    ->whereHas(
                        'role',
                        function (Builder $query): void {
                            $query->where(
                                'code',
                                'TU'
                            );
                        }
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                // Ambil kondisi akun terbaru
                // di dalam transaksi.
                $target = User::query()
                    ->with('role')
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                $previousRoleId = (int) $target->role_id;

                $newRoleId = array_key_exists(
                    'role_id',
                    $data
                )
                    ? (int) $data['role_id']
                    : $previousRoleId;

                $roleChanged =
                    $newRoleId !== $previousRoleId;

                // ==================================
                // VALIDASI PERUBAHAN ROLE
                // ==================================

                if ($roleChanged) {

                    // TU tidak boleh mengganti
                    // role akun sendiri.
                    if (
                        (int) Auth::id() ===
                        (int) $target->id
                    ) {
                        return ApiResponse::message(
                            'Role akun sendiri tidak dapat diubah melalui Manajemen Pengguna.',
                            null,
                            422
                        );
                    }

                    // Jangan menghilangkan
                    // TU aktif terakhir.
                    if (
                        $target->account_status === 'active' &&
                        $target->role?->code === 'TU' &&
                        $activeTuUsers->count() <= 1
                    ) {
                        return ApiResponse::message(
                            'Role akun TU aktif terakhir tidak dapat diubah.',
                            null,
                            422
                        );
                    }

                    $targetRoleCode = Role::query()
                        ->whereKey($newRoleId)
                        ->value('code');

                    // Akun yang belum aktivasi
                    // hanya boleh menggunakan
                    // alur aktivasi Guru/Siswa.
                    if (
                        $target->requires_activation &&
                        ! in_array(
                            $targetRoleCode,
                            ['GURU', 'SISWA'],
                            true
                        )
                    ) {
                        return ApiResponse::message(
                            'Akun yang belum aktivasi tidak dapat diubah menjadi TU atau Kepala Sekolah. Selesaikan aktivasi terlebih dahulu.',
                            null,
                            422
                        );
                    }

                    // Akun yang terhubung dengan
                    // profil Guru tidak boleh
                    // langsung berpindah role.
                    if (
                        $target->guru()->exists() &&
                        $targetRoleCode !== 'GURU'
                    ) {
                        return ApiResponse::message(
                            'Role akun tidak dapat diubah karena masih terhubung dengan profil Guru. Selesaikan perubahan identitas akademik melalui prosedur yang sesuai terlebih dahulu.',
                            null,
                            422
                        );
                    }

                    // Akun yang terhubung dengan
                    // profil Siswa juga dilindungi.
                    if (
                        $target->siswa()->exists() &&
                        $targetRoleCode !== 'SISWA'
                    ) {
                        return ApiResponse::message(
                            'Role akun tidak dapat diubah karena masih terhubung dengan profil Siswa. Selesaikan perubahan identitas akademik melalui prosedur yang sesuai terlebih dahulu.',
                            null,
                            422
                        );
                    }
                }

                // ==================================
                // SIMPAN PERUBAHAN
                // ==================================

                $target->update($data);

                if ($roleChanged) {

                    // Cabut semua sesi lama saat
                    // hak akses pengguna berubah.
                    $target->tokens()->delete();

                    Log::info(
                        'User role changed by administrator',
                        [
                            'actor_user_id' =>
                                Auth::id(),

                            'target_user_id' =>
                                $target->id,

                            'previous_role_id' =>
                                $previousRoleId,

                            'new_role_id' =>
                                $newRoleId,
                        ]
                    );
                }

                return ApiResponse::success(
                    new UserResource(
                        $target->load('role')
                    )
                );
            }
        );
    }

    // ==========================================
    // AKTIFKAN STATUS ADMINISTRATIF
    // ==========================================

    public function activate(User $user): JsonResponse
    {
        // Tidak mengubah requires_activation.
        // Akun hasil import tetap wajib aktivasi.
        $user->update([
            'account_status' => 'active',
        ]);

        return ApiResponse::message(
            'Akun berhasil diaktifkan.',
            new UserResource(
                $user->load('role')
            )
        );
    }

    // ==========================================
    // NONAKTIFKAN AKUN
    // ==========================================

    public function deactivate(
        User $user
    ): JsonResponse {

        // Larang menonaktifkan diri sendiri.
        if (
            (int) Auth::id() ===
            (int) $user->id
        ) {
            return ApiResponse::message(
                'Anda tidak dapat menonaktifkan akun sendiri.',
                null,
                422
            );
        }

        return DB::transaction(
            function () use ($user): JsonResponse {

                // Kunci akun TU aktif.
                $activeTuUsers = User::query()
                    ->where(
                        'account_status',
                        'active'
                    )
                    ->whereHas(
                        'role',
                        function (Builder $query): void {
                            $query->where(
                                'code',
                                'TU'
                            );
                        }
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                $targetIsTu = $user->role()
                    ->where(
                        'code',
                        'TU'
                    )
                    ->exists();

                // TU aktif terakhir tidak boleh
                // dinonaktifkan.
                if (
                    $targetIsTu &&
                    $user->account_status === 'active' &&
                    $activeTuUsers->count() <= 1
                ) {
                    return ApiResponse::message(
                        'Akun TU aktif terakhir tidak dapat dinonaktifkan.',
                        null,
                        422
                    );
                }

                $user->update([
                    'account_status' => 'inactive',
                ]);

                // Cabut semua token Sanctum.
                $user->tokens()->delete();

                Log::info(
                    'User account deactivated',
                    [
                        'actor_user_id' =>
                            Auth::id(),

                        'target_user_id' =>
                            $user->id,
                    ]
                );

                return ApiResponse::message(
                    'Akun berhasil dinonaktifkan. Seluruh token akses sebelumnya telah dicabut.',
                    new UserResource(
                        $user->load('role')
                    )
                );
            }
        );
    }

    // ==========================================
    // RESET PASSWORD PENGGUNA
    // ==========================================

    public function resetPassword(
        ResetUserPasswordRequest $request,
        User $user
    ): JsonResponse {

        // TU tidak boleh mereset password
        // akun sendiri melalui halaman ini.
        if (
            (int) Auth::id() ===
            (int) $user->id
        ) {
            return ApiResponse::message(
                'Gunakan fitur Ubah Password pada profil untuk mengubah password akun sendiri.',
                null,
                422
            );
        }

        // Jangan melewati kewajiban aktivasi.
        if ($user->requires_activation) {
            return ApiResponse::message(
                'Akun belum diaktivasi. Terbitkan Slip Aktivasi agar pengguna membuat password pribadinya terlebih dahulu.',
                null,
                422
            );
        }

        $validated = $request->validated();

        DB::transaction(
            function () use (
                $user,
                $validated
            ): void {

                // Model User menggunakan cast
                // password => hashed.
                $user->update([
                    'password' =>
                        $validated['password'],
                ]);

                // Logout seluruh sesi lama.
                $user->tokens()->delete();
            }
        );

        Log::info(
            'User password reset by administrator',
            [
                'actor_user_id' =>
                    Auth::id(),

                'target_user_id' =>
                    $user->id,
            ]
        );

        return ApiResponse::message(
            'Password pengguna berhasil direset. Token akses API sebelumnya telah dicabut.',
            null
        );
    }
}
