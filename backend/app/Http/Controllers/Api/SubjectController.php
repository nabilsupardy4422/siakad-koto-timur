<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Mapel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subjects = Mapel::query()
            ->latest('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            SubjectResource::collection($subjects),
            [
                'current_page' => $subjects->currentPage(),
                'per_page' => $subjects->perPage(),
                'total' => $subjects->total(),
                'last_page' => $subjects->lastPage(),
            ]
        );
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = Mapel::create($request->validated());

        return ApiResponse::message(
            'Data mata pelajaran berhasil ditambahkan.',
            new SubjectResource($subject),
            201
        );
    }

    public function show(Mapel $subject): JsonResponse
    {
        return ApiResponse::success(
            new SubjectResource($subject)
        );
    }

    public function update(
        UpdateSubjectRequest $request,
        Mapel $subject
    ): JsonResponse {
        $subject->update($request->validated());

        return ApiResponse::message(
            'Data mata pelajaran berhasil diperbarui.',
            new SubjectResource($subject->refresh())
        );
    }

    public function destroy(Mapel $subject): JsonResponse
    {
        $subject->delete();

        return ApiResponse::message(
            'Data mata pelajaran berhasil dihapus.'
        );
    }
}