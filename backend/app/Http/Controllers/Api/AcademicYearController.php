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
            ->when($request->user()->role?->code === 'TU', fn ($query) => $query->withExists(TahunAkademik::PERIOD_DEPENDENCIES))
            ->orderByDesc('tahun_mulai')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            AcademicYearResource::collection($academicYears)
        );
    }

    public function show(Request $request, TahunAkademik $academicYear): JsonResponse
    {
        if ($request->user()->role?->code === 'TU') {
            $academicYear->loadExists(TahunAkademik::PERIOD_DEPENDENCIES);
        }

        return ApiResponse::success(
            new AcademicYearResource($academicYear)
        );
    }

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $academicYear = DB::transaction(function () use ($validated) {
            $periods = TahunAkademik::lockForAdministrativeMutation();

            if ($periods->isEmpty()) {
                $validated['is_active'] = true;
            } elseif ($periods->where('is_active', true)->count() !== 1) {
                throw ValidationException::withMessages([
                    'is_active' => 'Periode aktif belum konsisten. Aktifkan satu periode yang sudah tersedia terlebih dahulu.',
                ]);
            } elseif ($validated['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'Buat periode tidak aktif terlebih dahulu, kemudian gunakan tindakan Aktifkan Periode.',
                ]);
            }

            $this->ensureUniqueIdentity($periods, $validated);
            return TahunAkademik::create($validated)->loadExists(TahunAkademik::PERIOD_DEPENDENCIES);
        }, 3);

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
            $periods = TahunAkademik::lockForAdministrativeMutation();

            $current = TahunAkademik::query()
                ->lockForUpdate()
                ->findOrFail($academicYear->id);

            $identityChanged = (int) $validated['tahun_mulai'] !== $current->tahun_mulai
                || (int) $validated['tahun_selesai'] !== $current->tahun_selesai
                || $validated['semester'] !== $current->semester;

            if ($identityChanged && ($current->is_active || $current->hasAcademicDependencies())) {
                throw ValidationException::withMessages([
                    'academic_year' => 'Identitas periode aktif atau yang sudah digunakan tidak dapat diubah.',
                ]);
            }

            if ($validated['is_active']) {
                if (array_key_exists('expected_active_ids', $validated)) {
                    $expected = array_map('intval', $validated['expected_active_ids']);
                    $actual = $periods->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    sort($expected);
                    sort($actual);
                    if ($expected !== $actual) {
                        abort(409, 'Periode aktif berubah sejak konfirmasi dibuka. Muat ulang dan tinjau kembali.');
                    }
                }
                if ($identityChanged) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Simpan perubahan identitas terlebih dahulu sebelum mengaktifkan periode.',
                    ]);
                }
                TahunAkademik::query()->where('id', '!=', $current->id)->where('is_active', true)->update(['is_active' => false]);
            } elseif ($current->is_active || $periods->where('is_active', true)->count() !== 1) {
                throw ValidationException::withMessages([
                    'is_active' => 'Tahun akademik aktif tidak dapat dinonaktifkan sebelum periode lain diaktifkan.',
                ]);
            }

            if ($identityChanged) {
                $this->ensureUniqueIdentity($periods, $validated, $current->id);
            }
            unset($validated['expected_active_ids']);
            $current->update($validated);

            return $current->refresh()->loadExists(TahunAkademik::PERIOD_DEPENDENCIES);
        }, 3);

        return ApiResponse::success(
            new AcademicYearResource($academicYear)
        );
    }

    public function destroy(TahunAkademik $academicYear): JsonResponse
    {
        try {
            DB::transaction(function () use ($academicYear): void {
                TahunAkademik::lockForAdministrativeMutation();
                $current = TahunAkademik::query()
                    ->lockForUpdate()
                    ->findOrFail($academicYear->id);

                if ($current->is_active) {
                    throw ValidationException::withMessages([
                        'academic_year' => 'Tahun akademik aktif tidak dapat dihapus. Aktifkan periode pengganti terlebih dahulu.',
                    ]);
                }

                if ($current->hasAcademicDependencies()) {
                    throw ValidationException::withMessages([
                        'academic_year' => 'Tahun akademik tidak dapat dihapus karena masih digunakan oleh data kelas atau data akademik lainnya.',
                    ]);
                }

                $current->delete();
            }, 3);
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
    private function ensureUniqueIdentity(\Illuminate\Support\Collection $periods, array $data, ?int $except = null): void
    {
        if ($periods->contains(fn ($period) => $period->id !== $except
            && $period->tahun_mulai === (int) $data['tahun_mulai']
            && $period->tahun_selesai === (int) $data['tahun_selesai']
            && $period->semester === $data['semester'])) {
            throw ValidationException::withMessages([
                'tahun_mulai' => 'Kombinasi tahun akademik dan semester tersebut sudah terdaftar.',
            ]);
        }
    }
}
