<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubmissionTugasLampiran;
use App\Models\TahunAkademik;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionAttachmentController extends Controller
{
    /**
     * Menampilkan lampiran langsung di browser,
     * jika format file didukung.
     */
    public function view(
        Request $request,
        SubmissionTugasLampiran $attachment
    ) {
        if (! $this->canAccessAttachment($request, $attachment)) {
            abort(403, 'Anda tidak memiliki akses ke lampiran ini.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->file_path)) {
            abort(404, 'File lampiran tidak ditemukan.');
        }

        $mimeType = $attachment->file_mime_type
            ?: 'application/octet-stream';

        // Hanya PDF dan gambar yang ditampilkan secara inline.
        // Format lain diarahkan ke unduhan.
        $inlineTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ];

        if (! in_array($mimeType, $inlineTypes, true)) {
            return $this->downloadFile($attachment);
        }

        return $disk->response(
            $attachment->file_path,
            $attachment->file_name,
            [
                'Content-Type' => $mimeType,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            'inline'
        );
    }

    /**
     * Mengunduh lampiran submission.
     */
    public function download(
        Request $request,
        SubmissionTugasLampiran $attachment
    ): StreamedResponse {
        if (! $this->canAccessAttachment($request, $attachment)) {
            abort(403, 'Anda tidak memiliki akses ke lampiran ini.');
        }

        return $this->downloadFile($attachment);
    }

    /**
     * Mengirim file sebagai unduhan setelah memastikan
     * file tersebut tersedia di disk privat.
     */
    private function downloadFile(
        SubmissionTugasLampiran $attachment
    ): StreamedResponse {
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->file_path)) {
            abort(404, 'File lampiran tidak ditemukan.');
        }

        return $disk->download(
            $attachment->file_path,
            $attachment->file_name,
            [
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    /**
     * Siswa hanya dapat mengakses lampirannya sendiri.
     * Guru hanya dapat mengakses lampiran pada jadwal
     * yang diajarnya.
     */
    private function canAccessAttachment(
        Request $request,
        SubmissionTugasLampiran $attachment
    ): bool {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $user->loadMissing(['role', 'siswa', 'guru']);

        $attachment->loadMissing([
            'submissionTugas.assignment.jadwalPelajaran',
        ]);

        $submission = $attachment->submissionTugas;
        $assignment = $submission?->assignment;
        $schedule = $assignment?->jadwalPelajaran;

        if (! $submission || ! $assignment || ! $schedule) {
            return false;
        }

        $role = $user->role?->code;

        if ($role === 'SISWA') {
            $student = $user->siswa;

            if (
                ! $student ||
                (int) $submission->siswa_id !== (int) $student->id
            ) {
                return false;
            }

            $activeAcademicYearId = TahunAkademik::query()
                ->where('is_active', true)
                ->value('id');

            if (
                $activeAcademicYearId === null ||
                (int) $schedule->tahun_akademik_id !==
                    (int) $activeAcademicYearId
            ) {
                return false;
            }

            return $student->anggotaKelas()
                ->where('kelas_id', $schedule->kelas_id)
                ->whereNull('tanggal_selesai')
                ->exists();
        }

        if ($role === 'GURU') {
            $guru = $user->guru;

            return $guru !== null
                && (int) $schedule->guru_id === (int) $guru->id;
        }

        return false;
    }
}
