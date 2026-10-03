<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Requests\UpdateSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\Assignment;
use App\Models\Guru;
use App\Models\SubmissionTugas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubmissionController extends Controller
{
    /**
     * Menampilkan seluruh submission dari sebuah assignment.
     *
     * Hanya guru yang mengajar jadwal terkait yang dapat
     * melihat daftar submission siswa.
     */
    public function index(
        Request $request,
        Assignment $assignment
    ) {
        if (! $this->canViewAssignmentSubmissions(
            $request,
            $assignment
        )) {
            abort(
                403,
                'Anda tidak memiliki akses ke submission assignment ini.'
            );
        }

        $submissions = $assignment
            ->submissionTugas()
            ->with([
                'siswa',
                'assignment.jadwalPelajaran',
            ])
            ->latest('submitted_at')
            ->paginate(15);

        return SubmissionResource::collection($submissions);
    }

    /**
     * Membuat submission baru untuk assignment.
     *
     * Satu siswa hanya memiliki satu submission aktif
     * untuk setiap assignment.
     */
    public function store(
        StoreSubmissionRequest $request,
        Assignment $assignment
    ): SubmissionResource {
        $user = $request->user();
        $student = $user?->siswa;

        if (
            ! $student ||
            ! $this->studentCanAccessAssignment(
                $student,
                $assignment
            )
        ) {
            abort(
                403,
                'Anda tidak memiliki akses ke assignment ini.'
            );
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
        $file = $request->file('file');

        $submission = DB::transaction(function () use (
            $assignment,
            $student,
            $data,
            $file
        ) {
            $submission = new SubmissionTugas();

            $submission->assignment_id = $assignment->id;
            $submission->siswa_id = $student->id;
            $submission->jawaban = $this->normalizeAnswer(
                $data['jawaban'] ?? null
            );
            $submission->submitted_at = now();

            $submission->status = now()->greaterThan(
                $assignment->deadline
            )
                ? 'terlambat'
                : 'dikumpulkan';

            if ($file) {
                $path = $file->store(
                    'submissions',
                    'local'
                );

                $submission->file_path = $path;
                $submission->file_name = $file->getClientOriginalName();
                $submission->file_mime_type = $file->getMimeType();
                $submission->file_size = $file->getSize();
            }

            $submission->save();

            return $submission;
        });

        $submission->load([
            'siswa',
            'assignment.jadwalPelajaran',
        ]);

        return new SubmissionResource($submission);
    }

    /**
     * Menampilkan detail submission.
     *
     * Siswa hanya dapat melihat submission miliknya sendiri.
     * Guru hanya dapat melihat submission dari assignment
     * yang berada pada jadwal mengajarnya.
     */
    public function show(
        Request $request,
        SubmissionTugas $submission
    ): SubmissionResource {
        if (! $this->canViewSubmission(
            $request,
            $submission
        )) {
            abort(
                403,
                'Anda tidak memiliki akses ke submission ini.'
            );
        }

        $submission->load([
            'siswa',
            'assignment.jadwalPelajaran',
        ]);

        return new SubmissionResource($submission);
    }

    /**
     * Melakukan revisi terhadap submission milik siswa.
     *
     * Revision menggunakan row submission yang sama,
     * bukan membuat row baru.
     */
    public function update(
        UpdateSubmissionRequest $request,
        SubmissionTugas $submission
    ): SubmissionResource {
        $user = $request->user();
        $student = $user?->siswa;

        if (! $student) {
            abort(
                403,
                'Akun siswa tidak ditemukan.'
            );
        }

        if ((int) $submission->siswa_id !== (int) $student->id) {
            abort(
                403,
                'Anda tidak memiliki akses untuk mengubah submission ini.'
            );
        }

        $submission->loadMissing([
            'assignment.jadwalPelajaran',
        ]);

        $assignment = $submission->assignment;

        if (
            ! $assignment ||
            ! $this->studentCanAccessAssignment(
                $student,
                $assignment
            )
        ) {
            abort(
                403,
                'Anda tidak memiliki akses ke assignment ini.'
            );
        }

        $data = $request->validated();
        $newFile = $request->file('file');

        DB::transaction(function () use (
            $submission,
            $assignment,
            $data,
            $newFile
        ) {
            if (array_key_exists('jawaban', $data)) {
                $submission->jawaban = $this->normalizeAnswer(
                    $data['jawaban']
                );
            }

            if ($newFile) {
                $oldFilePath = $submission->file_path;

                $path = $newFile->store(
                    'submissions',
                    'local'
                );

                $submission->file_path = $path;
                $submission->file_name = $newFile->getClientOriginalName();
                $submission->file_mime_type = $newFile->getMimeType();
                $submission->file_size = $newFile->getSize();

                if (
                    $oldFilePath &&
                    Storage::disk('local')->exists($oldFilePath)
                ) {
                    Storage::disk('local')->delete(
                        $oldFilePath
                    );
                }
            }

            $hasAnswer = filled(
                is_string($submission->jawaban)
                    ? trim($submission->jawaban)
                    : $submission->jawaban
            );

            $hasFile = filled($submission->file_path);

            if (! $hasAnswer && ! $hasFile) {
                abort(
                    422,
                    'Jawaban atau file wajib diisi.'
                );
            }

            $submission->submitted_at = now();

            $submission->status = now()->greaterThan(
                $assignment->deadline
            )
                ? 'terlambat'
                : 'dikumpulkan';

            $submission->save();
        });

        $submission->load([
            'siswa',
            'assignment.jadwalPelajaran',
        ]);

        return new SubmissionResource($submission);
    }

    /**
     * Authorization daftar submission assignment.
     *
     * Saat ini hanya GURU yang dapat melihat daftar
     * submission siswa pada assignment yang diajarnya.
     */
    private function canViewAssignmentSubmissions(
        Request $request,
        Assignment $assignment
    ): bool {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $role = $user->role?->code;

        if ($role !== 'GURU') {
            return false;
        }

        $guru = Guru::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $guru) {
            return false;
        }

        $assignment->loadMissing([
            'jadwalPelajaran',
        ]);

        return (int) $assignment
            ->jadwalPelajaran
            ?->guru_id === (int) $guru->id;
    }

    /**
     * Memastikan siswa merupakan anggota aktif
     * dari kelas assignment.
     */
    private function studentCanAccessAssignment(
        $student,
        Assignment $assignment
    ): bool {
        $assignment->loadMissing([
            'jadwalPelajaran',
        ]);

        $schedule = $assignment->jadwalPelajaran;

        if (! $schedule) {
            return false;
        }

        $today = now()->toDateString();

        return $student
            ->anggotaKelas()
            ->where('kelas_id', $schedule->kelas_id)
            ->whereDate(
                'tanggal_mulai',
                '<=',
                $today
            )
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('tanggal_selesai')
                    ->orWhereDate(
                        'tanggal_selesai',
                        '>=',
                        $today
                    );
            })
            ->exists();
    }

    /**
     * Authorization detail submission.
     */
    private function canViewSubmission(
        Request $request,
        SubmissionTugas $submission
    ): bool {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $role = $user->role?->code;

        $submission->loadMissing([
            'assignment.jadwalPelajaran',
        ]);

        if ($role === 'SISWA') {
            $student = $user->siswa;

            if (! $student) {
                return false;
            }

            return
                (int) $submission->siswa_id === (int) $student->id &&
                $submission->assignment &&
                $this->studentCanAccessAssignment(
                    $student,
                    $submission->assignment
                );
        }

        if ($role === 'GURU') {
            $guru = Guru::query()
                ->where('user_id', $user->id)
                ->first();

            if (! $guru) {
                return false;
            }

            return
                $submission->assignment &&
                (int) $submission
                    ->assignment
                    ->jadwalPelajaran
                    ?->guru_id === (int) $guru->id;
        }

        return false;
    }

    /**
     * Normalisasi jawaban text.
     */
    private function normalizeAnswer(
        ?string $answer
    ): ?string {
        if ($answer === null) {
            return null;
        }

        $answer = trim($answer);

        return $answer === ''
            ? null
            : $answer;
    }
}