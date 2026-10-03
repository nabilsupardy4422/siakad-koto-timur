<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportCardRequest;
use App\Http\Resources\ReportCardResource;
use App\Models\Rapor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportCardController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Rapor::class);

        $user = $request->user();

        $query = Rapor::query()
            ->with([
                'siswa',
                'tahunAkademik',
                'uploadedBy',
            ]);

        if ($user->role?->code === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return response()->json([
                    'message' => 'Data siswa tidak ditemukan.',
                ], 403);
            }

            $query->where('siswa_id', $studentId);
        }

        if ($request->filled('academic_year_id')) {
            $query->where(
                'tahun_akademik_id',
                $request->integer('academic_year_id')
            );
        }

        if ($request->filled('student_id')) {
            $query->where(
                'siswa_id',
                $request->integer('student_id')
            );
        }

        if ($request->filled('type')) {
            $query->where(
                'jenis_rapor',
                $request->string('type')->toString()
            );
        }

        if ($request->filled('semester')) {
            $semester = $request->string('semester')->toString();

            $query->whereHas(
                'tahunAkademik',
                function ($academicYearQuery) use ($semester) {
                    $academicYearQuery->where(
                        'semester',
                        $semester
                    );
                }
            );
        }

        $perPage = $request->integer('per_page', 15);

        $reportCards = $query
            ->latest('id')
            ->paginate($perPage);

        return ReportCardResource::collection(
            $reportCards
        );
    }

    public function store(
        StoreReportCardRequest $request
    ) {
        Gate::authorize('create', Rapor::class);

        $file = $request->file('file');

        $academicYearId = $request->integer(
            'tahun_akademik_id'
        );

        $studentId = $request->integer(
            'siswa_id'
        );

        $extension = $file->getClientOriginalExtension();

        $fileName = Str::uuid()
            .'.'
            .$extension;

        $directory =
            'report-cards/'
            .$academicYearId
            .'/'
            .$studentId;

        $filePath = $file->storeAs(
            $directory,
            $fileName,
            'local'
        );

        $reportCard = Rapor::create([
            'siswa_id' => $studentId,
            'tahun_akademik_id' => $academicYearId,
            'jenis_rapor' => $request->string(
                'jenis_rapor'
            )->toString(),
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'file_mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
        ]);

        $reportCard->load([
            'siswa',
            'tahunAkademik',
            'uploadedBy',
        ]);

        return (new ReportCardResource($reportCard))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Rapor $reportCard
    ) {
        Gate::authorize(
            'view',
            $reportCard
        );

        $reportCard->load([
            'siswa',
            'tahunAkademik',
            'uploadedBy',
        ]);

        return new ReportCardResource(
            $reportCard
        );
    }

    public function download(
        Rapor $reportCard
    ): BinaryFileResponse {
        Gate::authorize(
            'view',
            $reportCard
        );

        $disk = Storage::disk('local');

        if (! $disk->exists($reportCard->file_path)) {
            abort(404);
        }

        $absolutePath = $disk->path(
            $reportCard->file_path
        );

        return response()->download(
            $absolutePath,
            $reportCard->file_name,
            [
                'Content-Type' =>
                    $reportCard->file_mime_type,
            ]
        );
    }

    public function destroy(
        Rapor $reportCard
    ): JsonResponse {
        Gate::authorize(
            'delete',
            $reportCard
        );

        $disk = Storage::disk('local');

        if ($disk->exists($reportCard->file_path)) {
            $disk->delete(
                $reportCard->file_path
            );
        }

        $reportCard->delete();

        return response()->json([
            'message' =>
                'Rapor berhasil dihapus.',
        ]);
    }
}