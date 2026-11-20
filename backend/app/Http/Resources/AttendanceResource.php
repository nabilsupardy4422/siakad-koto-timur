<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,
            'siswa_id' => $this->siswa_id,

            'tanggal' => $this->tanggal?->toDateString(),
            'status' => $this->status,
            'catatan' => $this->catatan,

            'jadwal_pelajaran' => $this->whenLoaded(
                'jadwalPelajaran',
                function (): array {
                    $jadwal = $this->jadwalPelajaran;

                    return [
                        'id' => $jadwal->id,
                        'hari' => $jadwal->hari,
                        'jam_mulai' => $jadwal->jam_mulai,
                        'jam_selesai' => $jadwal->jam_selesai,

                        'mapel' => $jadwal->mapel ? [
                            'nama' => $jadwal->mapel->nama,
                        ] : null,

                        'guru' => $jadwal->guru ? [
                            'nama_lengkap' => $jadwal->guru->nama_lengkap,
                        ] : null,
                    ];
                }
            ),

            'siswa' => $this->whenLoaded(
                'siswa',
                function (): array {
                    return [
                        'id' => $this->siswa->id,
                        'nis' => $this->siswa->nis,
                        'nama_lengkap' => $this->siswa->nama_lengkap,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
