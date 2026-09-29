<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Models\BahanAjar;
use App\Models\JadwalPelajaran;
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
            ]);

        $this->applyViewScope($query, $request->user());

        if ($request->filled('academic_year_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                function ($schedule) use ($request): void {
                    $schedule->where(
                        'tahun_akademik_id',
                        $request->integer('academic_year_id')
                    );
                }
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

        $storedFile = null;

        try {
            if ($request->hasFile('file')) {
                $storedFile = $this->storeMaterialFile(
                    $request->file('file')
                );
            }

            $material = DB::transaction(
                function () use (
                    $request,
                    $storedFile
                ): BahanAjar {
                    return BahanAjar::create([
                        'jadwal_pelajaran_id' =>
                            $request->integer(
                                'jadwal_pelajaran_id'
                            ),
                        'judul' =>
                            $request->string('judul')->toString(),
                        'deskripsi' =>
                            $request->input('deskripsi'),
                        'file_path' =>
                            $storedFile['path'] ?? null,
                        'file_name' =>
                            $storedFile['name'] ?? null,
                        'file_mime_type' =>
                            $storedFile['mime_type'] ?? null,
                        'file_size' =>
                            $storedFile['size'] ?? null,
                        'created_by' =>
                            $request->user()->id,
                    ]);
                }
            );

            $material->load([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'createdBy',
            ]);

            return response()->json(
                [
                    'message' =>
                        'Bahan ajar berhasil dibuat.',
                    'data' =>
                        new MaterialResource($material),
                ],
                201
            );
        } catch (\Throwable $exception) {
            if ($storedFile !== null) {
                $this->deleteMaterialFile(
                    $storedFile['path']
                );
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
        ]);

        $currentSchedule = $material->jadwalPelajaran;

        if (
            !$this->canManageMaterial(
                $request->user(),
                $currentSchedule
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'Anda tidak memiliki akses untuk mengubah bahan ajar ini.',
                ],
                403
            );
        }

        $targetSchedule = $currentSchedule;

        if ($request->filled('jadwal_pelajaran_id')) {
            $targetSchedule = JadwalPelajaran::query()
                ->with([
                    'kelas',
                    'mapel',
                    'guru',
                ])
                ->findOrFail(
                    $request->integer(
                        'jadwal_pelajaran_id'
                    )
                );

            if (
                !$this->canManageMaterial(
                    $request->user(),
                    $targetSchedule
                )
            ) {
                return response()->json(
                    [
                        'message' =>
                            'Anda tidak memiliki akses untuk memindahkan bahan ajar ke jadwal tersebut.',
                    ],
                    403
                );
            }
        }

        $oldFilePath = $material->file_path;
        $newStoredFile = null;
        $fileChanged = false;

        try {
            if ($request->hasFile('file')) {
                $newStoredFile = $this->storeMaterialFile(
                    $request->file('file')
                );

                $fileChanged = true;
            } elseif (
                $request->boolean('remove_file')
                && $material->file_path !== null
            ) {
                $fileChanged = true;
            }

            DB::transaction(
                function () use (
                    $request,
                    $material,
                    $targetSchedule,
                    $newStoredFile,
                    $fileChanged
                ): void {
                    $data = [];

                    if ($request->has('jadwal_pelajaran_id')) {
                        $data['jadwal_pelajaran_id'] =
                            $targetSchedule->id;
                    }

                    if ($request->has('judul')) {
                        $data['judul'] =
                            $request->string(
                                'judul'
                            )->toString();
                    }

                    if ($request->has('deskripsi')) {
                        $data['deskripsi'] =
                            $request->input('deskripsi');
                    }

                    if ($fileChanged) {
                        $data['file_path'] =
                            $newStoredFile['path']
                            ?? null;

                        $data['file_name'] =
                            $newStoredFile['name']
                            ?? null;

                        $data['file_mime_type'] =
                            $newStoredFile['mime_type']
                            ?? null;

                        $data['file_size'] =
                            $newStoredFile['size']
                            ?? null;
                    }

                    $material->update($data);
                }
            );

            if (
                $fileChanged
                && $oldFilePath !== null
                && $oldFilePath !== (
                    $newStoredFile['path'] ?? null
                )
            ) {
                $this->deleteMaterialFile(
                    $oldFilePath
                );
            }

            $material->refresh();

            $material->load([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'createdBy',
            ]);

            return new MaterialResource($material);
        } catch (\Throwable $exception) {
            if ($newStoredFile !== null) {
                $this->deleteMaterialFile(
                    $newStoredFile['path']
                );
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
        $material->load([
            'jadwalPelajaran',
        ]);

        if (
            !$this->canManageMaterial(
                $request->user(),
                $material->jadwalPelajaran
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'Anda tidak memiliki akses untuk menghapus bahan ajar ini.',
                ],
                403
            );
        }

        $filePath = $material->file_path;

        DB::transaction(
            function () use ($material): void {
                $material->delete();
            }
        );

        $this->deleteMaterialFile($filePath);

        return response()->json(
            [
                'message' =>
                    'Bahan ajar berhasil dihapus.',
            ]
        );
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

        /*
         * TU and Kepala Sekolah can view according to
         * the current application policy.
         */
        if (
            $this->hasRole($user, 'TU')
            || $this->hasRole(
                $user,
                'KEPALA_SEKOLAH'
            )
        ) {
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
                fn ($member) => $member->where(
                    'siswa_id',
                    $siswaId
                )
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

        /*
         * TU and Kepala Sekolah.
         */
        if (
            $this->hasRole($user, 'TU')
            || $this->hasRole(
                $user,
                'KEPALA_SEKOLAH'
            )
        ) {
            return true;
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