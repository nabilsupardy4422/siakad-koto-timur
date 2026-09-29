<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scheduleId = $this->integer('jadwal_pelajaran_id');

        return [
            'jadwal_pelajaran_id' => [
                'required',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'nama' => [
                'required',
                'string',
                'max:100',
                Rule::unique('komponen_nilai', 'nama')
                    ->where(
                        fn ($query) => $query->where(
                            'jadwal_pelajaran_id',
                            $scheduleId
                        )
                    ),
            ],

            'bobot' => [
                'required',
                'numeric',
            ],
        ];
    }
}