<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $component = $this->komponenNilai;
        $schedule = $component?->jadwalPelajaran;
        $student = $this->siswa;

        return [
            'id' => $this->id,

            'komponen_nilai_id' => $this->komponen_nilai_id,
            'siswa_id' => $this->siswa_id,

            'nilai' => $this->nilai,
            'catatan' => $this->catatan,

            'komponen_nilai' => $component ? [
                'id' => $component->id,
                'nama' => $component->nama,
                'bobot' => $component->bobot,

                'jadwal_pelajaran' => $schedule ? [
                    'id' => $schedule->id,
                    'tahun_akademik_id' => $schedule->tahun_akademik_id,
                    'kelas_id' => $schedule->kelas_id,
                    'mapel_id' => $schedule->mapel_id,
                    'guru_id' => $schedule->guru_id,
                    'hari' => $schedule->hari,
                    'jam_mulai' => $schedule->jam_mulai,
                    'jam_selesai' => $schedule->jam_selesai,
                ] : null,
            ] : null,

            'siswa' => $student ? [
                'id' => $student->id,
                'nisn' => $student->nisn,
                'nis' => $student->nis,
                'nama_lengkap' => $student->nama_lengkap,
            ] : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}