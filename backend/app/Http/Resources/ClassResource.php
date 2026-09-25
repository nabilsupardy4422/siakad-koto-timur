<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tahun_akademik_id' => $this->tahun_akademik_id,
            'nama' => $this->nama,
            'tingkat' => $this->tingkat,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}