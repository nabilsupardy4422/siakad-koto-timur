<?php

namespace App\Http\Requests;

use App\Models\KomponenNilai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $component = $this->route('component');

        $componentId = $component instanceof KomponenNilai
            ? $component->id
            : (int) $component;

        $scheduleId = $this->filled('jadwal_pelajaran_id')
            ? $this->integer('jadwal_pelajaran_id')
            : (
                $component instanceof KomponenNilai
                    ? $component->jadwal_pelajaran_id
                    : null
            );

        return [
            'jadwal_pelajaran_id' => [
                'sometimes',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'nama' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('komponen_nilai', 'nama')
                    ->ignore($componentId)
                    ->where(
                        fn ($query) => $query->where(
                            'jadwal_pelajaran_id',
                            $scheduleId
                        )
                    ),
            ],

            'bobot' => [
                'sometimes',
                'numeric',
            ],
        ];
    }
}