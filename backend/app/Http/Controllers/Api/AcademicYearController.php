<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAcademicYearRequest;
use App\Http\Requests\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use App\Models\TahunAkademik;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $academicYears = TahunAkademik::query()
            ->when(
                $request->filled('semester'),
                fn ($query) => $query->where(
                    'semester',
                    $request->string('semester')->toString()
                )
            )
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where(
                    'is_active',
                    $request->boolean('is_active')
                )
            )
            ->get();

        return ApiResponse::success(
            AcademicYearResource::collection($academicYears)
        );
    }

    public function show(TahunAkademik $academicYear): JsonResponse
    {
        return ApiResponse::success(
            new AcademicYearResource($academicYear)
        );
    }

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $academicYear = TahunAkademik::create(
            $request->validated()
        );

        return ApiResponse::success(
            new AcademicYearResource($academicYear),
            201
        );
    }

    public function update(
        UpdateAcademicYearRequest $request,
        TahunAkademik $academicYear
    ): JsonResponse {
        $academicYear->update(
            $request->validated()
        );

        return ApiResponse::success(
            new AcademicYearResource($academicYear->refresh())
        );
    }

    public function destroy(TahunAkademik $academicYear): JsonResponse
    {
        $academicYear->delete();

        return ApiResponse::message(
            'Tahun akademik berhasil dihapus.'
        );
    }
}