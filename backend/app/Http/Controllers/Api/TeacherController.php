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

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $isTu = $request->user()?->role?->code === 'TU';
        $teachers = Guru::query()
            ->with('user')
            ->when(
                $request->filled('search'),
                function ($query) use ($request, $isTu): void {
                    $search = $request->string('search')->toString();

                    $query->where(function ($query) use ($search, $isTu): void {
                        $query->where('nip', 'like', "%{$search}%")
                            ->orWhere('nama_lengkap', 'like', "%{$search}%")
                            ->when($isTu, fn ($query) => $query->orWhereHas('user', function ($query) use ($search): void {
                                $query->where('username', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            }));
                    });
                }
            )
            ->when(
                $isTu && $request->filled('status'),
                function ($query) use ($request): void {
                    $query->whereHas('user', function ($query) use ($request): void {
                        $query->where(
                            'account_status',
                            $request->string('status')->toString()
                        );
                    });
                }
            )
            ->when($isTu, fn ($query) => \App\Support\IdentityDirectory::apply($query, $request, false))
            ->orderBy('nama_lengkap', $request->input('sort_order') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('page_size', $request->integer('per_page', 15)))))
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
        $teacher = app(\App\Services\AcademicIdentityService::class)->register('GURU', $request->validated());

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
        $teacher = app(\App\Services\AcademicIdentityService::class)->update($teacher, $request->validated());

        $teacher->load('user');

        return ApiResponse::success(
            new TeacherResource($teacher)
        );
    }

    public function destroy(Guru $teacher): JsonResponse
    {
        app(\App\Services\AcademicIdentityService::class)->remove($teacher);

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
            ->whereDoesntHave('guru')->whereDoesntHave('siswa')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($accounts);
    }
}
