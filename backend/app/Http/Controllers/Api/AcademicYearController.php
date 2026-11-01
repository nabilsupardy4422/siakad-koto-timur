<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAcademicYearRequest;
use App\Http\Requests\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use App\Models\TahunAkademik;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            ->orderByDesc('tahun_mulai')
            ->orderByDesc('id')
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
        $validated = $request->validated();

        $academicYear = DB::transaction(function () use ($validated) {
            // Kunci baris yang ada agar perubahan status periode dilakukan
            // secara terkoordinasi dalam transaksi.
            TahunAkademik::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $hasAcademicYears = TahunAkademik::query()->exists();
            $hasActiveAcademicYear = TahunAkademik::query()
                ->where('is_active', true)
                ->exists();

            // Jika belum ada periode aktif, periode pertama/berikutnya
            // wajib diaktifkan agar sistem tidak berjalan tanpa konteks aktif.
            if (
                ! $validated['is_active']
                && ! $hasActiveAcademicYear
            ) {
                throw ValidationException::withMessages([
                    'is_active' => $hasAcademicYears
                        ? 'Tidak ada tahun akademik aktif. Aktifkan periode baru agar sistem memiliki periode aktif.'
                        : 'Tahun akademik pertama harus berstatus aktif.',
                ]);
            }

            if ($validated['is_active']) {
                TahunAkademik::query()->update([
                    'is_active' => false,
                ]);
            }

            return TahunAkademik::create($validated);
        });

        return ApiResponse::success(
            new AcademicYearResource($academicYear),
            201
        );
    }

    public function update(
        UpdateAcademicYearRequest $request,
        TahunAkademik $academicYear
    ): JsonResponse {
        $validated = $request->validated();

        $academicYear = DB::transaction(function () use (
            $validated,
            $academicYear
        ) {
            TahunAkademik::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $current = TahunAkademik::query()
                ->lockForUpdate()
                ->findOrFail($academicYear->id);

            if ($validated['is_active']) {
                // Mengaktifkan periode ini sekaligus menonaktifkan semua
                // periode lain, dalam transaksi yang sama.
                TahunAkademik::query()
                    ->where('id', '!=', $current->id)
                    ->update([
                        'is_active' => false,
                    ]);
            } elseif ($current->is_active) {
                $anotherActiveYearExists = TahunAkademik::query()
                    ->where('id', '!=', $current->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $anotherActiveYearExists) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Tahun akademik aktif tidak dapat dinonaktifkan sebelum periode lain diaktifkan.',
                    ]);
                }
            } elseif (
                ! TahunAkademik::query()
                    ->where('is_active', true)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'is_active' => 'Tidak ada tahun akademik aktif. Aktifkan periode ini untuk memulihkan konteks akademik.',
                ]);
            }

            $current->update($validated);

            return $current->refresh();
        });

        return ApiResponse::success(
            new AcademicYearResource($academicYear)
        );
    }

    public function destroy(TahunAkademik $academicYear): JsonResponse
    {
        try {
            DB::transaction(function () use ($academicYear): void {
                $current = TahunAkademik::query()
                    ->lockForUpdate()
                    ->findOrFail($academicYear->id);

                if ($current->is_active) {
                    throw ValidationException::withMessages([
                        'academic_year' => 'Tahun akademik aktif tidak dapat dihapus. Aktifkan periode pengganti terlebih dahulu.',
                    ]);
                }

                $current->delete();
            });
        } catch (ValidationException $exception) {
            return ApiResponse::message(
                $exception->errors()['academic_year'][0]
                    ?? 'Tahun akademik aktif tidak dapat dihapus.'
            )->setStatusCode(422);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                return ApiResponse::message(
                    'Tahun akademik tidak dapat dihapus karena masih digunakan oleh data kelas atau data akademik lainnya.'
                )->setStatusCode(422);
            }

            throw $exception;
        }

        return ApiResponse::message(
            'Tahun akademik berhasil dihapus.'
        );
    }
}
