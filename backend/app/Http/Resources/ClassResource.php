<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $directory = in_array($request->user()?->role?->code, ['TU', 'KEPALA_SEKOLAH'], true) && isset($this->student_count);
        return [
            'id' => $this->id,
            'tahun_akademik_id' => $this->tahun_akademik_id,
            'nama' => $this->nama,
            'tingkat' => $this->tingkat,
            'student_count' => $this->when($directory, fn () => (int) $this->student_count),
            'homeroom_count' => $this->when($directory, fn () => (int) $this->homeroom_count),
            'homeroom_names' => $this->when($directory, fn () => $this->waliKelas->map(fn ($w) => $w->guru?->nama_lengkap)->filter()->unique()->values()->all()),
            'schedule_count' => $this->when($directory, fn () => (int) $this->schedule_count),
            'history' => $this->when($directory, fn () => ['members' => (int) $this->member_history_count, 'homerooms' => (int) $this->homeroom_history_count, 'schedules' => (int) $this->schedule_history_count]),
            'locked' => $this->when($directory, fn () => $this->member_history_count + $this->homeroom_history_count + $this->schedule_history_count > 0),
            'version' => $this->when($directory && $request->user()?->role?->code === 'TU', fn () => $this->resource->directoryVersion()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
