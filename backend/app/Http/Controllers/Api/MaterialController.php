<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Models\BahanAjar;
use App\Models\BahanAjarLampiran;
use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaterialController extends Controller
{
    /**
     * Display a listing of teaching materials.
     */
    public function index(Request $request)
    {
        $query = BahanAjar::query()
            ->with([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'createdBy',
                'lampiran',
            ]);

        $this->applyViewScope($query, $request->user());

        // Default to the active academic year unless a specific period is selected.
        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : TahunAkademik::query()
                ->where('is_active', true)
                ->value('id');

        if ($academicYearId === null) {
            // Fail closed: do not expose materials from every period if no active year exists.
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas(
                'jadwalPelajaran',
                fn ($schedule) => $schedule->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            );
        }

        if ($request->filled('schedule_id')) {
            $query->where(
                'jadwal_pelajaran_id',
                $request->integer('schedule_id')
            );
        }

        if ($request->filled('class_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                function ($schedule) use ($request): void {
                    $schedule->where(
                        'kelas_id',
                        $request->integer('class_id')
                    );
                }
            );
        }

        if ($request->filled('subject_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                function ($schedule) use ($request): void {
                    $schedule->where(
                        'mapel_id',
                        $request->integer('subject_id')
                    );
                }
            );
        }

        return MaterialResource::collection(
            $query
                ->latest()
                ->paginate(
                    $request->integer('per_page', 15)
                )
        );
    }

    /**
     * Store a newly created teaching material.
     */
    public function store(
        StoreMaterialRequest $request
    ): JsonResponse {
        $schedule = JadwalPelajaran::query()
            ->with([
                'kelas',
                'mapel',
                'guru',
            ])
            ->findOrFail(
                $request->integer('jadwal_pelajaran_id')
            );

        if (
            !$this->canManageMaterial(
                $request->user(),
                $schedule
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'Anda tidak memiliki akses untuk mengelola bahan ajar pada jadwal ini.',
                ],
                403
            );
        }

        $storedFiles = [];

        try {
            // Support the plural `files[]` contract and retain backward
            // compatibility with clients that still send a single `file`.
            $uploads = $request->file('files', []);
            if (!is_array($uploads)) {
                $uploads = [$uploads];
            }
            $uploads = array_values(array_filter($uploads));

            if ($uploads === [] && $request->hasFile('file')) {
                $uploads = [$request->file('file')];
            }

            foreach ($uploads as $upload) {
                $storedFiles[] = $this->storeMaterialFile($upload);
            }

            $primaryFile = $storedFiles[0] ?? null;

            $material = DB::transaction(
                function () use (
                    $request,
                    $storedFiles,
                    $primaryFile
                ): BahanAjar {
                    $material = BahanAjar::create([
                        'jadwal_pelajaran_id' =>
                            $request->integer('jadwal_pelajaran_id'),
                        'judul' => $request->string('judul')->toString(),
                        'deskripsi' => $request->input('deskripsi'),
                        // Keep the first upload in the legacy columns so the
                        // existing single-file download remains compatible.
                        'file_path' => $primaryFile['path'] ?? null,
                        'file_name' => $primaryFile['name'] ?? null,
                        'file_mime_type' => $primaryFile['mime_type'] ?? null,
                        'file_size' => $primaryFile['size'] ?? null,
                        'created_by' => $request->user()->id,
                    ]);

                    foreach ($storedFiles as $storedFile) {
                        $material->lampiran()->create([
                            'file_path' => $storedFile['path'],
                            'file_name' => $storedFile['name'],
                            'file_mime_type' => $storedFile['mime_type'],
                            'file_size' => $storedFile['size'],
                            'uploaded_by' => $request->user()->id,
                        ]);
                    }

                    return $material;
                }
            );

            $material->load([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'createdBy',
                'lampiran',
            ]);

            return response()->json(
                [
                    'message' => 'Bahan ajar berhasil dibuat.',
                    'data' => new MaterialResource($material),
                ],
                201
            );
        } catch (\Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                $this->deleteMaterialFile($storedFile['path'] ?? null);
            }

            throw $exception;
        }
    }

    /**
     * Display the specified teaching material.
     */
    public function show(
        Request $request,
        BahanAjar $material
    ): MaterialResource|JsonResponse {
        $material->load([
            'jadwalPelajaran.kelas',
            'jadwalPelajaran.mapel',
            'jadwalPelajaran.guru',
            'createdBy',
            'lampiran',
        ]);

        if (
            !$this->canViewMaterial(
                $request->user(),
                $material
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'Anda tidak memiliki akses untuk melihat bahan ajar ini.',
                ],
                403
            );
        }

        return new MaterialResource($material);
    }

    /**
     * Update the specified teaching material.
     */
    public function update(
        UpdateMaterialRequest $request,
        BahanAjar $material
    ): MaterialResource|JsonResponse {
        $material->load([
            'jadwalPelajaran.kelas',
            'jadwalPelajaran.mapel',
            'jadwalPelajaran.guru',
            'createdBy',
            'lampiran',
        ]);

        $currentSchedule = $material->jadwalPelajaran;

        if (!$this->canManageMaterial($request->user(), $currentSchedule)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah bahan ajar ini.',
            ], 403);
        }

        $targetSchedule = $currentSchedule;
        if ($request->filled('jadwal_pelajaran_id')) {
            $targetSchedule = JadwalPelajaran::query()
                ->with(['kelas', 'mapel', 'guru'])
                ->findOrFail($request->integer('jadwal_pelajaran_id'));

            if (!$this->canManageMaterial($request->user(), $targetSchedule)) {
                return response()->json([
                    'message' => 'Anda tidak memiliki akses untuk memindahkan bahan ajar ke jadwal tersebut.',
                ], 403);
            }
        }

        $newStoredFiles = [];
        $pathsToDelete = [];

        try {
            // Accept multiple files from the current frontend (files[]) and
            // retain compatibility with older clients that send a single file.
            $uploadedFiles = array_values(array_filter(
                (array) $request->file('files', []),
                static fn ($file): bool => $file !== null
            ));

            if ($request->hasFile('file')) {
                $uploadedFiles[] = $request->file('file');
            }

            foreach ($uploadedFiles as $uploadedFile) {
                $newStoredFiles[] = $this->storeMaterialFile($uploadedFile);
            }

            DB::transaction(function () use (
                $request,
                $material,
                $targetSchedule,
                $newStoredFiles,
                &$pathsToDelete
            ): void {
                $data = [];
                if ($request->has('jadwal_pelajaran_id')) {
                    $data['jadwal_pelajaran_id'] = $targetSchedule->id;
                }
                if ($request->has('judul')) {
                    $data['judul'] = $request->string('judul')->toString();
                }
                if ($request->has('deskripsi')) {
                    $data['deskripsi'] = $request->input('deskripsi');
                }
                $material->update($data);

                // Backfill legacy primary-file metadata for records created before
                // the attachment table was introduced. Avoid duplicate paths.
                if ($material->file_path && !$material->lampiran()->where('file_path', $material->file_path)->exists()) {
                    $material->lampiran()->create([
                        'file_path' => $material->file_path,
                        'file_name' => $material->file_name ?? basename($material->file_path),
                        'file_mime_type' => $material->file_mime_type,
                        'file_size' => $material->file_size,
                        'uploaded_by' => $request->user()->id,
                    ]);
                }

                // New uploads append all selected files. Existing attachments
                // remain available and are not replaced by an edit upload.
                if ($newStoredFiles !== []) {
                    foreach ($newStoredFiles as $storedFile) {
                        $material->lampiran()->create([
                            'file_path' => $storedFile['path'],
                            'file_name' => $storedFile['name'],
                            'file_mime_type' => $storedFile['mime_type'],
                            'file_size' => $storedFile['size'],
                            'uploaded_by' => $request->user()->id,
                        ]);

                        // Keep the legacy primary-file columns populated when
                        // a material did not have a primary file before.
                        if (!$material->file_path) {
                            $material->update([
                                'file_path' => $storedFile['path'],
                                'file_name' => $storedFile['name'],
                                'file_mime_type' => $storedFile['mime_type'],
                                'file_size' => $storedFile['size'],
                            ]);
                        }
                    }
                } elseif ($request->boolean('remove_file') && $material->file_path) {
                    $primaryPath = $material->file_path;
                    $primaryAttachment = $material->lampiran()
                        ->where('file_path', $primaryPath)
                        ->first();

                    if ($primaryAttachment) {
                        $primaryAttachment->delete();
                    }

                    $replacement = $material->lampiran()->orderBy('created_at')->orderBy('id')->first();
                    if ($replacement) {
                        $material->update([
                            'file_path' => $replacement->file_path,
                            'file_name' => $replacement->file_name,
                            'file_mime_type' => $replacement->file_mime_type,
                            'file_size' => $replacement->file_size,
                        ]);
                    } else {
                        $material->update([
                            'file_path' => null,
                            'file_name' => null,
                            'file_mime_type' => null,
                            'file_size' => null,
                        ]);
                    }

                    if (!$material->lampiran()->where('file_path', $primaryPath)->exists()) {
                        $pathsToDelete[] = $primaryPath;
                    }
                }
            });

            foreach (array_unique($pathsToDelete) as $path) {
                $this->deleteMaterialFile($path);
            }

            $material->refresh()->load([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'createdBy',
                'lampiran',
            ]);

            return new MaterialResource($material);
        } catch (\Throwable $exception) {
            foreach ($newStoredFiles as $storedFile) {
                $this->deleteMaterialFile($storedFile['path']);
            }
            throw $exception;
        }
    }

    /**
     * Remove the specified teaching material.
     */
    public function destroy(
        Request $request,
        BahanAjar $material
    ): JsonResponse {
        $material->load(['jadwalPelajaran', 'lampiran']);

        if (!$this->canManageMaterial($request->user(), $material->jadwalPelajaran)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk menghapus bahan ajar ini.',
            ], 403);
        }

        $paths = $material->lampiran->pluck('file_path')->push($material->file_path)
            ->filter()->unique()->values()->all();

        DB::transaction(function () use ($material): void {
            $material->lampiran()->delete();
            $material->delete();
        });

        foreach ($paths as $path) {
            $this->deleteMaterialFile($path);
        }

        return response()->json([
            'message' => 'Bahan ajar berhasil dihapus.',
        ]);
    }

    /**
     * Download the material file through a controlled endpoint.
     */
    public function download(
        Request $request,
        BahanAjar $material
    ): BinaryFileResponse|JsonResponse {
        $material->load([
            'jadwalPelajaran.kelas',
            'jadwalPelajaran.mapel',
            'jadwalPelajaran.guru',
        ]);

        if (
            !$this->canViewMaterial(
                $request->user(),
                $material
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'Anda tidak memiliki akses untuk mengunduh bahan ajar ini.',
                ],
                403
            );
        }

        if (
            $material->file_path === null
            || $material->file_path === ''
        ) {
            return response()->json(
                [
                    'message' =>
                        'Bahan ajar ini tidak memiliki file.',
                ],
                404
            );
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($material->file_path)) {
            return response()->json(
                [
                    'message' =>
                        'File bahan ajar tidak ditemukan.',
                ],
                404
            );
        }

        return response()->download(
            $disk->path($material->file_path),
            $material->file_name
                ?? basename($material->file_path),
            $material->file_mime_type
                ? [
                    'Content-Type' =>
                        $material->file_mime_type,
                ]
                : []
        );
    }

    /** Download one attachment after applying the material's access policy. */
    public function downloadAttachment(
        Request $request,
        BahanAjar $material,
        BahanAjarLampiran $attachment
    ): BinaryFileResponse|JsonResponse {
        $material->load(['jadwalPelajaran.kelas', 'jadwalPelajaran.mapel', 'jadwalPelajaran.guru']);

        if (!$this->canViewMaterial($request->user(), $material)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengunduh bahan ajar ini.',
            ], 403);
        }

        if ((int) $attachment->bahan_ajar_id !== (int) $material->id) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (!$disk->exists($attachment->file_path)) {
            return response()->json([
                'message' => 'File lampiran tidak ditemukan.',
            ], 404);
        }

        return response()->download(
            $disk->path($attachment->file_path),
            $attachment->file_name ?? basename($attachment->file_path),
            $attachment->file_mime_type
                ? ['Content-Type' => $attachment->file_mime_type]
                : []
        );
    }

    /** Delete one attachment without removing the other attachments. */
    public function destroyAttachment(
        Request $request,
        BahanAjar $material,
        BahanAjarLampiran $attachment
    ): JsonResponse {
        $material->load('jadwalPelajaran');

        if (!$this->canManageMaterial($request->user(), $material->jadwalPelajaran)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah bahan ajar ini.',
            ], 403);
        }

        if ((int) $attachment->bahan_ajar_id !== (int) $material->id) {
            abort(404);
        }

        $path = $attachment->file_path;
        DB::transaction(function () use ($material, $attachment, $path): void {
            $isPrimary = $material->file_path === $path;
            $attachment->delete();

            if ($isPrimary) {
                $replacement = $material->lampiran()->orderBy('created_at')->orderBy('id')->first();
                $material->update([
                    'file_path' => $replacement?->file_path,
                    'file_name' => $replacement?->file_name,
                    'file_mime_type' => $replacement?->file_mime_type,
                    'file_size' => $replacement?->file_size,
                ]);
            }
        });

        // Keep the physical file if any remaining record still references it.
        $stillReferenced = BahanAjarLampiran::query()->where('file_path', $path)->exists()
            || BahanAjar::query()->where('file_path', $path)->exists();
        if (!$stillReferenced) {
            $this->deleteMaterialFile($path);
        }

        return response()->json([
            'message' => 'Lampiran berhasil dihapus.',
        ]);
    }

    /**
     * Apply role-based viewing scope.
     */
    private function applyViewScope(
        $query,
        $user
    ): void {
        if ($user === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        // Only Guru and Siswa may access teaching materials.
        if (
            !$this->hasRole($user, 'GURU')
            && !$this->hasRole($user, 'SISWA')
        ) {
            $query->whereRaw('1 = 0');

            return;
        }

        /*
         * Guru can view:
         * 1. Materials from their own teaching schedules.
         * 2. Materials from their assigned Wali Kelas class.
         */
        if ($this->hasRole($user, 'GURU')) {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function (
                $materialQuery
            ) use ($guruId): void {
                $materialQuery->whereHas(
                    'jadwalPelajaran',
                    fn ($schedule) => $schedule->where(
                        'guru_id',
                        $guruId
                    )
                );

                $materialQuery->orWhereHas(
                    'jadwalPelajaran.kelas.waliKelas',
                    function ($waliKelas) use (
                        $guruId
                    ): void {
                        $waliKelas->where(
                            'guru_id',
                            $guruId
                        );
                    }
                );
            });

            return;
        }

        /*
         * Siswa can only view materials belonging
         * to their class.
         */
        if ($this->hasRole($user, 'SISWA')) {
            $siswaId = $user->siswa?->id;

            if ($siswaId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereHas(
                'jadwalPelajaran.kelas.anggotaKelas',
                function ($member) use ($siswaId): void {
                    $member->where('siswa_id', $siswaId)
                        ->where(function ($membership): void {
                            // Keanggotaan aktif: materi kelas saat ini.
                            $membership->whereNull('tanggal_selesai')
                                // Keanggotaan lama: hanya materi yang sudah
                                // dibuat sebelum/tepat pada tanggal pindah.
                                ->orWhereRaw(
                                    'DATE(bahan_ajar.created_at) <= anggota_kelas.tanggal_selesai'
                                );
                        });
                }
            );

            return;
        }

        /*
         * Unknown role receives no access.
         */
        $query->whereRaw('1 = 0');
    }

    /**
     * Determine whether a user can view a material.
     */
    private function canViewMaterial(
        $user,
        BahanAjar $material
    ): bool {
        if ($user === null) {
            return false;
        }

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        // Only Guru and Siswa may view or download teaching materials.
        if (
            !$this->hasRole($user, 'GURU')
            && !$this->hasRole($user, 'SISWA')
        ) {
            return false;
        }

        $schedule = $material->jadwalPelajaran;

        if ($schedule === null) {
            return false;
        }

        /*
         * Guru.
         */
        if ($this->hasRole($user, 'GURU')) {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                return false;
            }

            if ($schedule->guru_id === $guruId) {
                return true;
            }

            if ($schedule->kelas === null) {
                return false;
            }

            return $schedule->kelas
                ->waliKelas()
                ->where('guru_id', $guruId)
                ->where(
                    'tahun_akademik_id',
                    $schedule->tahun_akademik_id
                )
                ->exists();
        }

        /*
         * Siswa.
         */
        if ($this->hasRole($user, 'SISWA')) {
            $siswaId = $user->siswa?->id;

            if (
                $siswaId === null
                || $schedule->kelas === null
            ) {
                return false;
            }

            return $schedule->kelas
                ->anggotaKelas()
                ->where('siswa_id', $siswaId)
                ->where(function ($membership) use ($material): void {
                    $membership->whereNull('tanggal_selesai')
                        ->orWhereDate(
                            'tanggal_selesai',
                            '>=',
                            $material->created_at
                        );
                })
                ->exists();
        }

        return false;
    }

    /**
     * Determine whether a user can create, update,
     * or delete a material for the specified schedule.
     */
    private function canManageMaterial(
        $user,
        ?JadwalPelajaran $schedule
    ): bool {
        if ($user === null || $schedule === null) {
            return false;
        }

        $user->loadMissing([
            'role',
            'guru',
        ]);

        if (!$this->hasRole($user, 'GURU')) {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        return $schedule->guru_id === $guruId;
    }

    /**
     * Determine whether the authenticated user has
     * the given role.
     */
    private function hasRole(
        $user,
        string $role
    ): bool {
        return $user->role?->code === $role;
    }

    /**
     * Store an uploaded material file using a generated
     * safe filename.
     */
    private function storeMaterialFile($file): array
    {
        $extension =
            $file->getClientOriginalExtension();

        $filename = (string) Str::uuid();

        if ($extension !== '') {
            $filename .= '.'
                . strtolower($extension);
        }

        $path = $file->storeAs(
            'materials',
            $filename,
            'local'
        );

        return [
            'path' => $path,
            'name' =>
                $file->getClientOriginalName(),
            'mime_type' =>
                $file->getMimeType(),
            'size' =>
                $file->getSize(),
        ];
    }

    /**
     * Delete a stored material file when it exists.
     */
    private function deleteMaterialFile(
        ?string $path
    ): void {
        if ($path === null || $path === '') {
            return;
        }

        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
