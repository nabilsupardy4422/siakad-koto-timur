<?php

namespace App\Http\Controllers\Api;

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
        $teacher->delete();

        return ApiResponse::message(
            'Data guru berhasil dihapus.'
        );
    }
}