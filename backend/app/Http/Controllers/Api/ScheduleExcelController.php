<?php

namespace App\Http\Controllers\Api;

use App\Exports\ScheduleExport;
use App\Imports\ScheduleImport;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ScheduleExcelController extends Controller
{
    /**
     * Download template Excel jadwal pelajaran.
     */
    public function template()
    {
        $template = new class implements FromArray, WithHeadings, WithColumnFormatting {
            public function array(): array
            {
                return [];
            }

            public function headings(): array
            {
                return [
                    'tahun_akademik',
                    'semester',
                    'tingkat',
                    'kelas',
                    'kode_mapel',
                    'nip_guru',
                    'hari',
                    'jam_mulai',
                    'jam_selesai',
                ];
            }

            /**
             * Kolom F (NIP Guru) menggunakan format teks.
             */
            public function columnFormats(): array
            {
                return [
                    'F' => NumberFormat::FORMAT_TEXT,
                ];
            }
        };

        return Excel::download(
            $template,
            'template-jadwal-pelajaran.xlsx'
        );
    }

    /**
     * Import jadwal pelajaran dari file Excel.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
            ],
        ]);

        try {
            Excel::import(
                new ScheduleImport(),
                $validated['file']
            );

            return ApiResponse::message(
                'Data jadwal pelajaran berhasil diimport.',
                [
                    'status' => 'success',
                ]
            );
        } catch (ValidationException $exception) {
            return ApiResponse::message(
                'Import jadwal gagal. Periksa kembali data Excel.',
                [
                    'errors' => $exception->errors(),
                ],
                422
            );
        }
    }

    /**
     * Export jadwal pelajaran ke Excel.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => [
                'nullable',
                'integer',
                'exists:tahun_akademik,id',
            ],
            'semester' => [
                'nullable',
                'string',
                'max:20',
            ],
            'class_id' => [
                'nullable',
                'integer',
                'exists:kelas,id',
            ],
            'subject_id' => [
                'nullable',
                'integer',
                'exists:mapel,id',
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                'exists:guru,id',
            ],
            'day' => [
                'nullable',
                'string',
                'max:20',
            ],
        ]);

        $filters = array_filter(
            $validated,
            fn ($value) => $value !== null && $value !== ''
        );

        return Excel::download(
            new ScheduleExport($filters),
            'jadwal-pelajaran.xlsx'
        );
    }
}
