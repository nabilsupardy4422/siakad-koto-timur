<?php

namespace App\Support;

use App\Models\AnggotaKelas;
use App\Models\TahunAkademik;
use Illuminate\Support\Carbon;

/**
 * One authoritative, effective student membership for the active school period.
 * A scheduled/ended membership never grants access to recurring schedules.
 */
final class StudentScheduleAccess
{
    public static function resolve(?int $studentId, TahunAkademik $year): array
    {
        // School membership dates use the WIB calendar day, not the server's UTC day.
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $base = [
            'status' => 'unassigned',
            'as_of' => $today,
            'academic_year' => [
                'id' => (int) $year->id,
                'tahun_mulai' => (int) $year->tahun_mulai,
                'tahun_selesai' => (int) $year->tahun_selesai,
                'semester' => $year->semester,
            ],
            'class' => null,
            'starts_on' => null,
            'ends_on' => null,
        ];

        if ($studentId === null) {
            return $base;
        }

        $memberships = AnggotaKelas::query()
            ->with(['kelas:id,tahun_akademik_id,nama,tingkat'])
            ->where('siswa_id', $studentId)
            ->whereHas('kelas', fn ($query) => $query->where('tahun_akademik_id', $year->id))
            ->orderBy('tanggal_mulai')
            ->get();

        $active = $memberships->filter(fn ($member) =>
            $member->tanggal_mulai !== null &&
            $member->tanggal_mulai->toDateString() <= $today &&
            ($member->tanggal_selesai === null || $member->tanggal_selesai->toDateString() >= $today)
        )->values();

        if ($active->count() > 1) {
            return [...$base, 'status' => 'ambiguous'];
        }

        if ($active->count() === 1) {
            $member = $active->first();
            if (! $member->kelas) {
                return [...$base, 'status' => 'ambiguous'];
            }

            return [
                ...$base,
                'status' => 'active',
                'class' => [
                    'id' => (int) $member->kelas->id,
                    'nama' => $member->kelas->nama,
                    'tingkat' => (int) $member->kelas->tingkat,
                ],
                'starts_on' => $member->tanggal_mulai->toDateString(),
                'ends_on' => $member->tanggal_selesai?->toDateString(),
            ];
        }

        $future = $memberships->filter(fn ($member) =>
            $member->tanggal_mulai !== null && $member->tanggal_mulai->toDateString() > $today
        )->sortBy('tanggal_mulai')->first();

        if ($future) {
            return [...$base, 'status' => 'scheduled', 'starts_on' => $future->tanggal_mulai->toDateString()];
        }

        if ($memberships->isNotEmpty()) {
            return [...$base, 'status' => 'ended'];
        }

        return $base;
    }
}
