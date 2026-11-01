<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tahun_akademik_id' => [
                'required',
                'integer',
                'exists:tahun_akademik,id',
            ],
            'nama' => [
                'required',
                'string',
                'max:100',
                Rule::unique('kelas', 'nama')
                    ->where(fn ($query) => $query
                        ->where(
                            'tahun_akademik_id',
                            $this->integer('tahun_akademik_id')
                        )
                        ->where('tingkat', $this->integer('tingkat'))),
            ],
            'tingkat' => [
                'required',
                'integer',
                Rule::in([10, 11, 12]),
            ],
        ];
    }
}
