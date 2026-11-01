<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeacherRequest;
use App\Http\Requests\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\Guru;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\WaliKelas;

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teachers = Guru::query()
            ->with('user')
            ->when(
                $request->filled('search'),
                function ($query) use ($request): void {
                    $search = $request->string('search')->toString();

                    $query->where(function ($query) use ($search): void {
                        $query->where('nip', 'like', "%{$search}%")
                            ->orWhere('nama_lengkap', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($query) use ($search): void {
                                $query->where('username', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
                }
            )
            ->when(
                $request->filled('status'),
                function ($query) use ($request): void {
                    $query->whereHas('user', function ($query) use ($request): void {
                        $query->where(
                            'account_status',
                            $request->string('status')->toString()
                        );
                    });
                }
            )
            ->latest('id')
            ->paginate($request->integer('page_size', 15))
            ->withQueryString();

        return ApiResponse::collection(
            TeacherResource::collection($teachers->items()),
            [
                'current_page' => $teachers->currentPage(),
                'last_page' => $teachers->lastPage(),
                'per_page' => $teachers->perPage(),
                'total' => $teachers->total(),
            ]
        );
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $teacher = Guru::create($request->validated());

        $teacher->load('user');

        return ApiResponse::success(
            new TeacherResource($teacher),
            201
        );
    }

    public function show(Guru $teacher): JsonResponse
    {
        $teacher->load('user');

        return ApiResponse::success(
            new TeacherResource($teacher)
        );
    }

    public function update(
        UpdateTeacherRequest $request,
        Guru $teacher
    ): JsonResponse {
        $teacher->update($request->validated());

        $teacher->load('user');

        return ApiResponse::success(
            new TeacherResource($teacher)
        );
    }

    public function destroy(Guru $teacher): JsonResponse
    {
        $hasHomeroomHistory = WaliKelas::query()
            ->where('guru_id', $teacher->id)
            ->exists();

        if ($hasHomeroomHistory) {
            return response()->json([
                'message' => 'Data guru tidak dapat dihapus karena masih memiliki riwayat penugasan Wali Kelas.',
            ], 422);
        }

        $teacher->delete();

        return ApiResponse::message(
            'Data guru berhasil dihapus.'
        );
    }

    public function availableAccounts(): JsonResponse
    {
        $accounts = User::query()
            ->select(['id', 'username', 'name', 'email'])
            ->where('account_status', 'active')
            ->whereHas('role', function (Builder $query): void {
                $query->where('code', 'GURU');
            })
            ->whereDoesntHave('guru')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($accounts);
    }
}