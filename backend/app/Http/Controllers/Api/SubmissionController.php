<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Requests\UpdateSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\Assignment;
use App\Models\Guru;
use App\Models\SubmissionTugas;
use App\Models\TahunAkademik;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SubmissionController extends Controller
{
    /**
     * Menampilkan submission dari sebuah assignment.
     *
     * Guru hanya dapat melihat submission pada jadwal
     * yang diajarnya. Siswa hanya dapat melihat miliknya.
     */
    public function index(Request $request, Assignment $assignment)
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        $user->loadMissing(['role', 'siswa']);

        $role = $user->role?->code;

        if ($role === 'GURU') {
            if (! $this->canViewAssignmentSubmissions($request, $assignment)) {
                abort(403, 'Anda tidak memiliki akses ke submission assignment ini.');
            }

            $query = $assignment->submissionTugas()
                ->with([
                    'siswa',
                    'assignment.jadwalPelajaran',
                    'lampiran',
                ]);
        } elseif ($role === 'SISWA') {
            $student = $user->siswa;

            if (
                ! $student ||
                ! $this->studentCanAccessAssignment($student, $assignment)
            ) {
                abort(403, 'Anda tidak memiliki akses ke assignment ini.');
            }

            $query = $assignment->submissionTugas()
                ->where('siswa_id', $student->id)
                ->with([
                    'assignment.jadwalPelajaran',
                    'lampiran',
                ]);
        } else {
            abort(403, 'Anda tidak memiliki akses ke submission assignment ini.');
        }

        $submissions = $query->latest('submitted_at')->paginate(15);

        return \App\Support\ApiResponse::collection(
            SubmissionResource::collection($submissions->items()),
            [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
            ]
        );
    }

    /**
     * Mengumpulkan jawaban tugas baru.
     *
     * Mendukung field file lama dan banyak lampiran.
     * Pengumpulan pertama yang terlambat tetap diberi
     * status terlambat.
     */
    public function store(
        StoreSubmissionRequest $request,
        Assignment $assignment
    ): SubmissionResource {
        $user = $request->user();
        $student = $user?->siswa;

        if (
            ! $student ||
            ! $this->studentCanAccessAssignment($student, $assignment)
        ) {
            abort(403, 'Anda tidak memiliki akses ke assignment ini.');
        }

        $existingSubmission = SubmissionTugas::query()
            ->where('assignment_id', $assignment->id)
            ->where('siswa_id', $student->id)
            ->first();

        if ($existingSubmission) {
            abort(
                422,
                'Submission untuk assignment ini sudah ada. Gunakan endpoint update untuk melakukan revisi.'
            );
        }

        $data = $request->validated();
        $answer = $this->normalizeAnswer($data['jawaban'] ?? null);
        $files = $this->uploadedFiles($request);

        if ($answer === null && count($files) === 0) {
            abort(422, 'Jawaban atau minimal satu file wajib diisi.');
        }

        $storedPaths = [];

        try {
            $submission = DB::transaction(function () use (
                $assignment,
                $student,
                $answer,
                $files,
                &$storedPaths
            ) {
                $submission = new SubmissionTugas();
                $submission->assignment_id = $assignment->id;
                $submission->siswa_id = $student->id;
                $submission->jawaban = $answer;
                $submission->submitted_at = now();
                $submission->status = $this->submissionStatus($assignment);

                $submission->save();

                $this->storeAttachments(
                    $submission,
                    $files,
                    $storedPaths,
                    true
                );

                $submission->save();

                return $submission;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($storedPaths);

            throw $exception;
        }

        $submission->load([
            'siswa',
            'assignment.jadwalPelajaran',
            'lampiran',
        ]);

        return new SubmissionResource($submission);
    }

    /**
     * Menampilkan detail submission.
     */
    public function show(
        Request $request,
        SubmissionTugas $submission
    ): SubmissionResource {
        if (! $this->canViewSubmission($request, $submission)) {
            abort(403, 'Anda tidak memiliki akses ke submission ini.');
        }

        $submission->load([
            'siswa',
            'assignment.jadwalPelajaran',
            'lampiran',
        ]);

        return new SubmissionResource($submission);
    }

    /**
     * Merevisi submission milik siswa.
     *
     * Lampiran baru ditambahkan, bukan menggantikan
     * lampiran sebelumnya.
     */
    public function update(
        UpdateSubmissionRequest $request,
        SubmissionTugas $submission
    ): SubmissionResource {
        $user = $request->user();
        $student = $user?->siswa;

        if (! $student) {
            abort(403, 'Akun siswa tidak ditemukan.');
        }

        if ((int) $submission->siswa_id !== (int) $student->id) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah submission ini.');
        }

        $submission->loadMissing(['assignment.jadwalPelajaran']);

        $assignment = $submission->assignment;

        if (
            ! $assignment ||
            ! $this->studentCanAccessAssignment($student, $assignment)
        ) {
            abort(403, 'Anda tidak memiliki akses ke assignment ini.');
        }

        // Revisi tidak boleh dilakukan setelah tenggat.
        // Tepat pada waktu tenggat masih diperbolehkan.
        if (
            $assignment->deadline !== null &&
            now()->greaterThan($assignment->deadline)
        ) {
            abort(
                422,
                'Batas waktu revisi telah berakhir. Jawaban tidak dapat diubah lagi.'
            );
        }

        $data = $request->validated();
        $files = $this->uploadedFiles($request);

        $answer = array_key_exists('jawaban', $data)
            ? $this->normalizeAnswer($data['jawaban'])
            : $submission->jawaban;

        $hasExistingAttachment = $submission->file_path !== null
            || $submission->lampiran()->exists();

        if ($answer === null && ! $hasExistingAttachment && count($files) === 0) {
            abort(422, 'Jawaban atau minimal satu file wajib diisi.');
        }

        $storedPaths = [];

        try {
            DB::transaction(function () use (
                $submission,
                $assignment,
                $data,
                $files,
                &$storedPaths
            ) {
                if (array_key_exists('jawaban', $data)) {
                    $submission->jawaban = $this->normalizeAnswer(
                        $data['jawaban']
                    );
                }

                // Jika submission lama hanya menggunakan kolom file,
                // salin metadatanya ke tabel lampiran terlebih dahulu.
                $this->preserveLegacyAttachment($submission);

                // Lampiran baru ditambahkan tanpa menghapus yang lama.
                $this->storeAttachments(
                    $submission,
                    $files,
                    $storedPaths,
                    true
                );

                $hasAnswer = $this->normalizeAnswer($submission->jawaban) !== null;
                $hasAttachment = $submission->file_path !== null
                    || $submission->lampiran()->exists();

                if (! $hasAnswer && ! $hasAttachment) {
                    abort(422, 'Jawaban atau minimal satu file wajib diisi.');
                }

                $submission->submitted_at = now();
                $submission->status = $this->submissionStatus($assignment);
                $submission->save();
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($storedPaths);

            throw $exception;
        }

        $submission->load([
            'siswa',
            'assignment.jadwalPelajaran',
            'lampiran',
        ]);

        return new SubmissionResource($submission);
    }

    /**
     * Menggabungkan field file lama dan field files[].
     *
     * Mendukung kompatibilitas dengan frontend lama.
     *
     * @return array<int, UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->file('files', []);

        if (! is_array($files)) {
            $files = [];
        }

        $legacyFile = $request->file('file');

        if ($legacyFile instanceof UploadedFile) {
            array_unshift($files, $legacyFile);
        }

        return array_values(array_filter(
            $files,
            fn ($file) => $file instanceof UploadedFile
        ));
    }

    /**
     * Menyimpan lampiran dan metadata ke database.
     *
     * @param array<int, UploadedFile> $files
     * @param array<int, string> $storedPaths
     */
    private function storeAttachments(
        SubmissionTugas $submission,
        array $files,
        array &$storedPaths,
        bool $setLegacyFields = false
    ): void {
        foreach ($files as $file) {
            $path = $file->store('submissions', 'local');

            if (! $path) {
                throw new \RuntimeException('File lampiran gagal disimpan.');
            }

            $storedPaths[] = $path;

            $metadata = [
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size' => $file->getSize(),
            ];

            $submission->lampiran()->create($metadata);

            // Pertahankan kolom lama untuk kompatibilitas API.
            if ($setLegacyFields && ! $submission->file_path) {
                $submission->file_path = $metadata['file_path'];
                $submission->file_name = $metadata['file_name'];
                $submission->file_mime_type = $metadata['file_mime_type'];
                $submission->file_size = $metadata['file_size'];
            }
        }
    }

    /**
     * Menyalin lampiran dari kolom lama ke tabel lampiran
     * sebelum menambahkan file baru.
     */
    private function preserveLegacyAttachment(
        SubmissionTugas $submission
    ): void {
        if (! $submission->file_path) {
            return;
        }

        $alreadyExists = $submission->lampiran()
            ->where('file_path', $submission->file_path)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        $submission->lampiran()->create([
            'file_path' => $submission->file_path,
            'file_name' => $submission->file_name ?: basename($submission->file_path),
            'file_mime_type' => $submission->file_mime_type ?: 'application/octet-stream',
            'file_size' => $submission->file_size ?? 0,
        ]);
    }

    /**
     * Membersihkan file baru jika transaksi database gagal.
     *
     * @param array<int, string> $paths
     */
    private function deleteStoredFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /**
     * Menentukan status berdasarkan deadline.
     */
    private function submissionStatus(Assignment $assignment): string
    {
        if (
            $assignment->deadline !== null &&
            now()->greaterThan($assignment->deadline)
        ) {
            return 'terlambat';
        }

        return 'dikumpulkan';
    }

    /**
     * Otorisasi daftar submission assignment.
     */
    private function canViewAssignmentSubmissions(
        Request $request,
        Assignment $assignment
    ): bool {
        $user = $request->user();

        if (! $user || $user->role?->code !== 'GURU') {
            return false;
        }

        $guru = Guru::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $guru) {
            return false;
        }

        $assignment->loadMissing(['jadwalPelajaran']);

        return (int) $assignment->jadwalPelajaran?->guru_id
            === (int) $guru->id;
    }

    /**
     * Memastikan siswa terdaftar di kelas assignment
     * pada tahun akademik aktif.
     */
    private function studentCanAccessAssignment(
        $student,
        Assignment $assignment
    ): bool {
        $assignment->loadMissing(['jadwalPelajaran']);

        $schedule = $assignment->jadwalPelajaran;

        if (! $schedule) {
            return false;
        }

        $activeAcademicYearId = TahunAkademik::query()
            ->where('is_active', true)
            ->value('id');

        if (
            $activeAcademicYearId === null ||
            (int) $schedule->tahun_akademik_id !== (int) $activeAcademicYearId
        ) {
            return false;
        }

        return $student->anggotaKelas()
            ->where('kelas_id', $schedule->kelas_id)
            ->whereNull('tanggal_selesai')
            ->exists();
    }

    /**
     * Otorisasi detail submission.
     */
    private function canViewSubmission(
        Request $request,
        SubmissionTugas $submission
    ): bool {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $user->loadMissing(['role', 'siswa']);

        $role = $user->role?->code;

        $submission->loadMissing(['assignment.jadwalPelajaran']);

        if ($role === 'SISWA') {
            $student = $user->siswa;

            return $student !== null
                && (int) $submission->siswa_id === (int) $student->id
                && $submission->assignment !== null
                && $this->studentCanAccessAssignment(
                    $student,
                    $submission->assignment
                );
        }

        if ($role === 'GURU') {
            $guru = Guru::query()
                ->where('user_id', $user->id)
                ->first();

            return $guru !== null
                && $submission->assignment !== null
                && (int) $submission->assignment->jadwalPelajaran?->guru_id
                    === (int) $guru->id;
        }

        return false;
    }

    /**
     * Normalisasi jawaban teks.
     */
    private function normalizeAnswer(?string $answer): ?string
    {
        if ($answer === null) {
            return null;
        }

        $answer = trim($answer);

        return $answer === '' ? null : $answer;
    }
}
