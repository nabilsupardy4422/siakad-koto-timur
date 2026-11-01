<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('role')
            ->when(
                $request->filled('search'),
                function (Builder $query) use ($request): void {
                    $search = $request->string('search')->toString();

                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('username', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
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
                    $request->string('account_status')->toString()
                )
            )
            ->latest('id')
            ->paginate(min(max($request->integer('page_size', 15), 1), 100))
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

    public function store(StoreUserRequest $request): JsonResponse
    {
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

    public function show(User $user): JsonResponse
    {
        $user->load('role');

        return ApiResponse::success(
            new UserResource($user)
        );
    }

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

        // Periksa kompatibilitas role hanya jika role_id dikirim.
        if (array_key_exists('role_id', $data)) {
            $targetRoleCode = Role::query()
                ->whereKey($data['role_id'])
                ->value('code');

            $hasGuruProfile = $user->guru()->exists();
            $hasSiswaProfile = $user->siswa()->exists();

            if ($hasGuruProfile && $targetRoleCode !== 'GURU') {
                return ApiResponse::message(
                    'Role akun tidak dapat diubah karena masih terhubung dengan profil Guru. Selesaikan perubahan identitas akademik melalui prosedur yang sesuai terlebih dahulu.',
                    null,
                    422
                );
            }

            if ($hasSiswaProfile && $targetRoleCode !== 'SISWA') {
                return ApiResponse::message(
                    'Role akun tidak dapat diubah karena masih terhubung dengan profil Siswa. Selesaikan perubahan identitas akademik melalui prosedur yang sesuai terlebih dahulu.',
                    null,
                    422
                );
            }
        }

        $user->update($data);

        $user->load('role');

        return ApiResponse::success(
            new UserResource($user)
        );
    }

    public function activate(User $user): JsonResponse
    {
        $user->update([
            'account_status' => 'active',
        ]);

        return ApiResponse::message(
            'Akun berhasil diaktifkan.',
            new UserResource($user->load('role'))
        );
    }


    public function deactivate(User $user): JsonResponse
    {
        // Mencegah TU menonaktifkan akunnya sendiri.
        if ((int) Auth::id() === (int) $user->id) {
            return ApiResponse::message(
                'Anda tidak dapat menonaktifkan akun sendiri.',
                null,
                422
            );
        }

        return DB::transaction(function () use ($user): JsonResponse {
            // Kunci baris akun TU yang masih aktif untuk mencegah
            // dua permintaan bersamaan menonaktifkan seluruh akun TU.
            $activeTuUsers = User::query()
                ->where('account_status', 'active')
                ->whereHas('role', function (Builder $query): void {
                    $query->where('code', 'TU');
                })
                ->lockForUpdate()
                ->get(['id']);

            $targetIsTu = $user->role()
                ->where('code', 'TU')
                ->exists();

            // Jangan menonaktifkan akun TU aktif terakhir.
            if (
                $targetIsTu
                && $user->account_status === 'active'
                && $activeTuUsers->count() <= 1
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

            return ApiResponse::message(
                'Akun berhasil dinonaktifkan.',
                new UserResource($user->load('role'))
            );
        });
    }

}
