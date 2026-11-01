<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\AssignmentLampiran;
use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing(['role', 'guru', 'siswa']);

        $query = Assignment::query()
            ->with([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'komponenNilai',
                'lampiran',
            ])
            ->latest('deadline');

        $this->applyViewScope($query, $user);

        if ($user->role?->code === 'GURU') {
            $activeAcademicYearId = TahunAkademik::query()
                ->where('is_active', true)
                ->value('id');

            if ($activeAcademicYearId === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas(
                    'jadwalPelajaran',
                    fn (Builder $builder) => $builder->where(
                        'tahun_akademik_id',
                        $activeAcademicYearId
                    )
                );
            }
        } elseif ($request->filled('academic_year_id')) {
            $academicYearId = $request->integer('academic_year_id');

            $query->whereHas(
                'jadwalPelajaran',
                fn (Builder $builder) => $builder->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            );
        }

        $assignments = $query
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            AssignmentResource::collection($assignments->items()),
            [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ]
        );
    }

    public function store(
        StoreAssignmentRequest $request
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (
            $user->role?->code !== 'GURU'
            || ! $user->guru
        ) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk membuat tugas.',
            ], 403);
        }

        $activeAcademicYearId = TahunAkademik::query()
            ->where('is_active', true)
            ->value('id');

        if (! $activeAcademicYearId) {
            return response()->json([
                'message' => 'Tahun akademik aktif belum tersedia.',
            ], 422);
        }

        $validated = $request->validated();

        $scheduleAllowed = JadwalPelajaran::query()
            ->whereKey($validated['jadwal_pelajaran_id'])
            ->where('guru_id', $user->guru->id)
            ->where('tahun_akademik_id', $activeAcademicYearId)
            ->exists();

        if (! $scheduleAllowed) {
            return response()->json([
                'message' => 'Jadwal harus milik Anda dan berada pada tahun akademik aktif.',
            ], 422);
        }

        $storedPaths = [];

        try {
            $assignment = DB::transaction(function () use (
                $request,
                $validated,
                &$storedPaths
            ): Assignment {
                $assignment = new Assignment();

                $assignment->fill([
                    'jadwal_pelajaran_id' =>
                        $validated['jadwal_pelajaran_id'],

                    'komponen_nilai_id' =>
                        $validated['komponen_nilai_id'] ?? null,

                    'judul' => $validated['judul'],

                    'deskripsi' => $validated['deskripsi'] ?? null,

                    'deadline' => $validated['deadline'],

                    'created_by' => $request->user()->id,
                ]);

                /*
                 * Format lama: satu berkas dengan field "file".
                 * Metadata disimpan pada tabel assignments.
                 */
                if ($request->hasFile('file')) {
                    $file = $request->file('file');

                    $path = $file->store('assignments', 'local');

                    if ($path === false) {
                        throw new \RuntimeException(
                            'Berkas tugas gagal disimpan.'
                        );
                    }

                    $storedPaths[] = $path;

                    $assignment->file_path = $path;
                    $assignment->file_name =
                        $file->getClientOriginalName();
                    $assignment->file_mime_type =
                        $file->getMimeType()
                        ?: 'application/octet-stream';
                    $assignment->file_size = $file->getSize();
                }

                $assignment->save();

                /*
                 * Format baru: beberapa berkas dengan field "files[]".
                 * Metadata disimpan pada tabel assignment_lampiran.
                 */
                foreach ($request->file('files', []) as $file) {
                    $path = $file->store('assignments', 'local');

                    if ($path === false) {
                        throw new \RuntimeException(
                            'Salah satu lampiran gagal disimpan.'
                        );
                    }

                    $storedPaths[] = $path;

                    $assignment->lampiran()->create([
                        'file_path' => $path,

                        'file_name' =>
                            $file->getClientOriginalName(),

                        'file_mime_type' =>
                            $file->getMimeType()
                            ?: 'application/octet-stream',

                        'file_size' => $file->getSize(),
                    ]);
                }

                return $assignment;
            });
        } catch (\Throwable $exception) {
            // Bersihkan berkas baru jika transaksi gagal.
            Storage::disk('local')->delete($storedPaths);

            report($exception);

            return response()->json([
                'message' => 'Tugas atau lampiran gagal disimpan.',
            ], 500);
        }

        return ApiResponse::message(
            'Tugas berhasil ditambahkan.',
            new AssignmentResource(
                $assignment->load([
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                    'komponenNilai',
                    'lampiran',
                ])
            ),
            201
        );
    }

    public function show(
        Request $request,
        Assignment $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru', 'siswa']);

        if (! $this->canViewAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return ApiResponse::success(
            new AssignmentResource(
                $assignment->load([
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                    'komponenNilai',
                    'lampiran',
                ])
            )
        );
    }

    public function update(
        UpdateAssignmentRequest $request,
        Assignment $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canManageAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah tugas ini.',
            ], 403);
        }

        $validated = $request->validated();

        $activeAcademicYearId = TahunAkademik::query()
            ->where('is_active', true)
            ->value('id');

        if ($activeAcademicYearId === null) {
            return response()->json([
                'message' => 'Tidak ada tahun akademik aktif. Tugas tidak dapat diubah.',
            ], 422);
        }

        if (array_key_exists('jadwal_pelajaran_id', $validated)) {
            $scheduleAllowed = $user->guru?->id !== null
                && JadwalPelajaran::query()
                    ->whereKey($validated['jadwal_pelajaran_id'])
                    ->where('guru_id', $user->guru->id)
                    ->where('tahun_akademik_id', $activeAcademicYearId)
                    ->exists();

            if (! $scheduleAllowed) {
                return response()->json([
                    'message' => 'Jadwal tujuan harus milik Anda dan berada pada tahun akademik aktif.',
                ], 422);
            }
        }

        /*
         * ID lampiran yang hendak dihapus dikirim terpisah dari data tugas.
         * Hanya lampiran milik tugas ini yang boleh dihapus.
         */
        $removeIds = $request->input('remove_attachment_ids', []);

        if (! is_array($removeIds)) {
            return response()->json([
                'message' => 'Daftar lampiran yang akan dihapus tidak valid.',
            ], 422);
        }

        $removeIds = array_values(array_unique(array_filter(
            $removeIds,
            fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false
                && (int) $id > 0
        )));

        $storedPaths = [];
        $deletedPaths = [];

        try {
            DB::transaction(function () use (
                $request,
                $assignment,
                $validated,
                $removeIds,
                &$storedPaths,
                &$deletedPaths
            ): void {
                $assignment->fill(
                    collect($validated)
                        ->except([
                            'files',
                            'remove_file',
                            'remove_attachment_ids',
                        ])
                        ->toArray()
                );

                /*
                 * Hapus lampiran berdasarkan ID yang dikirim.
                 * Jika path-nya sama dengan file lama pada tabel assignments,
                 * bersihkan juga metadata file lama.
                 */
                foreach ($removeIds as $attachmentId) {
                    $attachment = $assignment->lampiran()
                        ->whereKey($attachmentId)
                        ->first();

                    if (! $attachment) {
                        continue;
                    }

                    $attachmentPath = $attachment->file_path;

                    $deletedPaths[] = $attachmentPath;

                    if (
                        $assignment->file_path !== null
                        && $assignment->file_path === $attachmentPath
                    ) {
                        $assignment->file_path = null;
                        $assignment->file_name = null;
                        $assignment->file_mime_type = null;
                        $assignment->file_size = null;
                    }

                    $attachment->delete();
                }

                /*
                 * Kompatibilitas dengan klien lama yang mengirim remove_file.
                 * File lama beserta baris relasi yang memakai path sama
                 * dihapus secara konsisten.
                 */
                if (
                    $request->boolean('remove_file')
                    && $assignment->file_path !== null
                ) {
                    $legacyPath = $assignment->file_path;

                    $matchingAttachments = $assignment->lampiran()
                        ->where('file_path', $legacyPath)
                        ->get();

                    foreach ($matchingAttachments as $attachment) {
                        $deletedPaths[] = $attachment->file_path;
                        $attachment->delete();
                    }

                    $deletedPaths[] = $legacyPath;

                    $assignment->file_path = null;
                    $assignment->file_name = null;
                    $assignment->file_mime_type = null;
                    $assignment->file_size = null;
                }

                $assignment->save();

                /*
                 * Tambahkan lampiran baru tanpa menghapus lampiran lain
                 * yang tidak dipilih untuk dihapus.
                 */
                foreach ($request->file('files', []) as $file) {
                    $path = $file->store('assignments', 'local');

                    if ($path === false) {
                        throw new \RuntimeException(
                            'Salah satu lampiran gagal disimpan.'
                        );
                    }

                    $storedPaths[] = $path;

                    $assignment->lampiran()->create([
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_mime_type' => $file->getMimeType()
                            ?: 'application/octet-stream',
                        'file_size' => $file->getSize(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            /*
             * Jika transaksi gagal, hapus file baru yang sempat disimpan.
             * File lama tidak dihapus pada blok catch ini.
             */
            Storage::disk('local')->delete($storedPaths);

            report($exception);

            return response()->json([
                'message' => 'Perubahan tugas atau lampiran gagal disimpan.',
            ], 500);
        }

        /*
         * Hapus file fisik setelah transaksi database berhasil.
         */
        if ($deletedPaths !== []) {
            Storage::disk('local')->delete(
                array_values(array_unique($deletedPaths))
            );
        }

        return ApiResponse::message(
            'Tugas berhasil diperbarui.',
            new AssignmentResource(
                $assignment->refresh()->load([
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                    'komponenNilai',
                    'lampiran',
                ])
            )
        );
    }

    public function destroy(
        Request $request,
        Assignment $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canManageAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk menghapus tugas ini.',
            ], 403);
        }

        if ($assignment->submissionTugas()->exists()) {
            return response()->json([
                'message' => 'Tugas tidak dapat dihapus karena sudah memiliki submission siswa.',
            ], 422);
        }

        $paths = $assignment->lampiran()
            ->pluck('file_path')
            ->all();

        if ($assignment->file_path !== null) {
            $paths[] = $assignment->file_path;
        }

        DB::transaction(function () use ($assignment): void {
            $assignment->lampiran()->delete();
            $assignment->delete();
        });

        if ($paths !== []) {
            Storage::disk('local')->delete(
                array_values(array_unique($paths))
            );
        }

        return ApiResponse::message('Tugas berhasil dihapus.');
    }

    /**
     * Endpoint unduhan lama tetap tersedia.
     * Jika ada banyak lampiran, endpoint ini mengunduh lampiran pertama.
     */
    public function download(
        Request $request,
        Assignment $assignment
    ) {
        $user = $request->user();

        $user->loadMissing(['role', 'guru', 'siswa']);

        if (! $this->canViewAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $attachment = $assignment->lampiran()
            ->oldest('id')
            ->first();

        if ($attachment) {
            return $this->downloadStoredFile(
                $attachment->file_path,
                $attachment->file_name
            );
        }

        if ($assignment->file_path !== null) {
            return $this->downloadStoredFile(
                $assignment->file_path,
                $assignment->file_name ?? 'lampiran-tugas'
            );
        }

        return response()->json([
            'message' => 'Tugas ini tidak memiliki file lampiran.',
        ], 404);
    }

    /**
     * Mengunduh satu lampiran berdasarkan ID-nya.
     */
    public function downloadAttachment(
        Request $request,
        AssignmentLampiran $attachment
    ) {
        $user = $request->user();

        $user->loadMissing(['role', 'guru', 'siswa']);

        $assignment = $attachment->assignment()
            ->with('jadwalPelajaran')
            ->first();

        if (
            ! $assignment
            || ! $this->canViewAssignment($assignment, $user)
        ) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke lampiran ini.',
            ], 403);
        }

        return $this->downloadStoredFile(
            $attachment->file_path,
            $attachment->file_name
        );
    }

    private function downloadStoredFile(
        string $path,
        string $filename
    ) {
        if (! Storage::disk('local')->exists($path)) {
            return response()->json([
                'message' => 'File lampiran tidak ditemukan.',
            ], 404);
        }

        return Storage::disk('local')->download($path, $filename);
    }

    private function applyViewScope(
        Builder $query,
        User $user
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            $activeAcademicYearId = TahunAkademik::query()
                ->where('is_active', true)
                ->value('id');

            if ($guruId === null || $activeAcademicYearId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereHas(
                'jadwalPelajaran',
                function (Builder $builder) use (
                    $guruId,
                    $activeAcademicYearId
                ): void {
                    $builder
                        ->where('guru_id', $guruId)
                        ->where('tahun_akademik_id', $activeAcademicYearId);
                }
            );

            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereHas(
                'jadwalPelajaran.kelas.anggotaKelas',
                function (Builder $builder) use ($studentId): void {
                    $builder
                        ->where('siswa_id', $studentId)
                        ->whereNull('tanggal_selesai');
                }
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewAssignment(
        Assignment $assignment,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return true;
        }

        if ($role === 'GURU') {
            return $assignment->jadwalPelajaran?->guru_id
                === $user->guru?->id;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return false;
            }

            return $assignment->jadwalPelajaran
                ->kelas()
                ->whereHas(
                    'anggotaKelas',
                    function (Builder $builder) use ($studentId): void {
                        $builder
                            ->where('siswa_id', $studentId)
                            ->whereNull('tanggal_selesai');
                    }
                )
                ->exists();
        }

        return false;
    }

    private function canManageAssignment(
        Assignment $assignment,
        User $user
    ): bool {
        if ($user->role?->code !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        $activeAcademicYearId = TahunAkademik::query()
            ->where('is_active', true)
            ->value('id');

        if ($activeAcademicYearId === null) {
            return false;
        }

        $schedule = $assignment->jadwalPelajaran;

        return $schedule !== null
            && (int) $schedule->guru_id === (int) $guruId
            && (int) $schedule->tahun_akademik_id
                === (int) $activeAcademicYearId;
    }
}
