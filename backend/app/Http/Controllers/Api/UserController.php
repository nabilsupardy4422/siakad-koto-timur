<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Role;
use App\Services\ActivationCodeMonitor;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    // ==========================================
    // DAFTAR PENGGUNA
    // ==========================================

    public function roles(): JsonResponse
    {
        return ApiResponse::success(Role::query()->whereIn('code', ['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'])->orderBy('id')->get(['id', 'code', 'name']));
    }

    public function activationSummary(): JsonResponse
    {
        $counts = User::query()->selectRaw("COUNT(*) as total, SUM(CASE WHEN account_status = 'active' AND requires_activation = 0 THEN 1 ELSE 0 END) as ready, SUM(CASE WHEN account_status = 'active' AND requires_activation = 1 THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN account_status = 'inactive' THEN 1 ELSE 0 END) as inactive")->first();
        $total = (int) $counts->total;
        $ready = (int) $counts->ready;
        $pending = (int) $counts->pending;
        $inactive = (int) $counts->inactive;
        return ApiResponse::success(['total'=>$total, 'ready'=>$ready, 'pending'=>$pending, 'inactive'=>$inactive,
            'unknown'=>$total-$ready-$pending-$inactive, 'code_monitor'=>ActivationCodeMonitor::counts()])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'account_state'=>['sometimes','in:ready,pending,inactive'],
            'code_state'=>['sometimes','in:'.implode(',',ActivationCodeMonitor::STATES)],
            'profile_state'=>['sometimes','in:linked,unlinked'],
            'academic_year_id'=>['sometimes','integer','exists:tahun_akademik,id'],
            'class_id'=>['sometimes','integer','exists:kelas,id'],
            'placement_state'=>['sometimes','in:unassigned'],
        ]);
        $asOf = now();
        $users = User::query()->select('users.*')->selectRaw(ActivationCodeMonitor::sql().' AS code_state',[$asOf])
            ->addSelect(['code_expires_at'=>DB::table('user_activation_tokens')->select('expires_at')->whereColumn('user_id','users.id')])
            ->when($request->filled('code_state'), fn (Builder $q) => $q->whereRaw('('.ActivationCodeMonitor::sql().') = ?',[$asOf,$request->input('code_state')]))
            ->with(['role', 'guru:id,user_id', 'siswa:id,user_id'])
            ->when($request->filled('user_id'), fn (Builder $query) => $query->whereKey($request->integer('user_id')))
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
            ->when($request->filled('account_state'), function (Builder $q) use ($request) {
                $state = $request->input('account_state');
                $q->where('account_status', $state === 'inactive' ? 'inactive' : 'active');
                if ($state !== 'inactive') $q->where('requires_activation', $state === 'pending');
            })
            ->when($request->filled('profile_state'), function (Builder $q) use ($request) {
                $linked = $request->input('profile_state') === 'linked';
                $q->where(function (Builder $q) use ($linked) {
                    $q->where(function (Builder $q) use ($linked) {
                        $q->whereHas('role', fn ($r) => $r->where('code','GURU'))->whereDoesntHave('siswa');
                        $linked ? $q->whereHas('guru') : $q->whereDoesntHave('guru');
                    })->orWhere(function (Builder $q) use ($linked) {
                        $q->whereHas('role', fn ($r) => $r->where('code','SISWA'))->whereDoesntHave('guru');
                        $linked ? $q->whereHas('siswa') : $q->whereDoesntHave('siswa');
                    });
                });
            });
        if ($request->filled('academic_year_id') || $request->filled('class_id') || $request->filled('placement_state')) {
            $period = \App\Support\StudentPlacement::period($request);
            abort_if($period === null, 422, 'Periode akademik tidak tersedia.');
            $class = $request->filled('class_id') ? $request->integer('class_id') : null;
            abort_if($class !== null && ! \App\Models\Kelas::query()->whereKey($class)->where('tahun_akademik_id',$period)->exists(), 422, 'Kelas tidak sesuai periode.');
            abort_if($class !== null && $request->filled('placement_state'), 422, 'Pilih kelas atau belum ditempatkan.');
            $effective = \App\Models\TahunAkademik::query()->whereKey($period)->where('is_active',true)->exists();
            $memberships = fn (Builder $q) => \App\Support\StudentPlacement::scope($q, $period, $class, $effective);
            $users->whereHas('role', fn ($q) => $q->where('code','SISWA'))->whereDoesntHave('guru')
                ->whereHas('siswa', function (Builder $q) use ($request, $memberships) {
                    $request->input('placement_state') === 'unassigned' ? $q->whereDoesntHave('anggotaKelas',$memberships) : $q->whereHas('anggotaKelas',$memberships);
                });
        }
        $users = $users->orderBy('name', $request->input('sort_order') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
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
        $roleCode = Role::query()->whereKey($request->validated('role_id'))->value('code');
        if (in_array($roleCode, ['GURU', 'SISWA'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['role_id' => 'Daftarkan Guru atau Siswa melalui Data Master agar akun dan profil dibuat bersama.']);
        }

        $user = User::create([
            ...$request->safe()->only([
                'username',
                'name',
                'email',
                'role_id',
            ]),
            'password' => Hash::make($request->validated('password')),
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
        $user->load(['role', 'guru:id,user_id', 'siswa:id,user_id']);

        return ApiResponse::success(
            new UserResource(ActivationCodeMonitor::decorate($user))
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
                    if (in_array($targetRoleCode, ['GURU', 'SISWA'], true)) {
                        return ApiResponse::message('Perubahan menjadi Guru atau Siswa memerlukan prosedur identitas akademik. Tidak dapat dilakukan melalui edit akun.', null, 422);
                    }
                }

                // ==================================
                // SIMPAN PERUBAHAN
                // ==================================

                $academicProfile = $target->role?->code === 'GURU' ? $target->guru()->first() : ($target->role?->code === 'SISWA' ? $target->siswa()->first() : null);
                if ($academicProfile && array_key_exists('name', $data) && $data['name'] !== $academicProfile->nama_lengkap) {
                    return ApiResponse::message('Nama resmi diperbarui melalui Data Master.', null, 422);
                }
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

                $user = User::query()->lockForUpdate()->findOrFail($user->id);

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

                // Cabut sesi dan kode aktivasi yang masih berlaku.
                $user->tokens()->delete();
                DB::table('user_activation_tokens')->where('user_id', $user->id)->whereNull('used_at')->delete();

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

                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_if($locked->requires_activation, 422, 'Akun belum diaktivasi. Terbitkan Slip Aktivasi terlebih dahulu.');
                $locked->forceFill([
                    'password' => Hash::make($validated['password']),
                    'remember_token' => null,
                ])->save();

                // Logout seluruh sesi lama.
                $locked->tokens()->delete();
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
