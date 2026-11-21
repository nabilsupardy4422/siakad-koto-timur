<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TahunAkademik;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class AcademicYearExcelController extends Controller
{
    private const MAX_ROWS = 1000;

    private const HEADERS = [
        'tahun_mulai',
        'tahun_selesai',
        'semester',
        'is_active',
    ];

    /**
     * Download template Excel.
     */
    public function template()
    {
        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setTitle('Tahun Akademik');

        $sheet->fromArray(
            self::HEADERS,
            null,
            'A1'
        );

        $sheet->getStyle('A1:D1')
            ->getFont()
            ->setBold(true);

        $sheet->setCellValue('A2', '2026');
        $sheet->setCellValue('B2', '2027');
        $sheet->setCellValue('C2', 'GANJIL');
        $sheet->setCellValue('D2', 1);

        foreach (range('A', 'D') as $column) {
            $sheet->getColumnDimension($column)
                ->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer, $spreadsheet) {
                $writer->save('php://output');

                $spreadsheet->disconnectWorksheets();
            },
            'template_tahun_akademik.xlsx',
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

    /**
     * Preview dan validasi file Excel.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:2048',
            ],
        ]);

        try {
            $result = $this->readAndValidate(
                $validated['file']->getRealPath()
            );
        } catch (ValidationException $exception) {
            return ApiResponse::message(
                'Validasi file Excel gagal.',
                [
                    'errors' => $exception->errors(),
                ],
                422
            );
        }

        return ApiResponse::message(
            'Preview selesai. Belum ada tahun akademik yang disimpan.',
            [
                'summary' => [
                    'total_rows' => count($result['rows']),
                    'valid_rows' => $result['valid_rows'],
                    'invalid_rows' => $result['invalid_rows'],
                ],
                'rows' => $result['rows'],
            ]
        );
    }

    /**
     * Import data setelah konfirmasi.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:2048',
            ],
        ]);

        try {
            $result = $this->readAndValidate(
                $validated['file']->getRealPath()
            );

            if ($result['invalid_rows'] > 0) {
                throw ValidationException::withMessages([
                    'file' => 'Import dibatalkan karena terdapat baris yang tidak valid.',
                ]);
            }

            $importedCount = DB::transaction(
                function () use ($result) {
                    // Kunci data untuk menjaga konsistensi
                    // status tahun akademik aktif.
                    TahunAkademik::query()
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    // Periksa kembali duplikasi di database.
                    foreach ($result['normalized_rows'] as $row) {
                        $exists = TahunAkademik::query()
                            ->where('tahun_mulai', $row['tahun_mulai'])
                            ->where('tahun_selesai', $row['tahun_selesai'])
                            ->where('semester', $row['semester'])
                            ->exists();

                        if ($exists) {
                            throw ValidationException::withMessages([
                                'file' =>
                                    "Periode {$row['tahun_mulai']}/{$row['tahun_selesai']} semester {$row['semester']} sudah terdaftar.",
                            ]);
                        }
                    }

                    $activeInFile = collect($result['normalized_rows'])
                        ->where('is_active', true)
                        ->count();

                    if ($activeInFile > 1) {
                        throw ValidationException::withMessages([
                            'file' =>
                                'Maksimal satu tahun akademik boleh berstatus aktif.',
                        ]);
                    }

                    $hasActiveInDatabase = TahunAkademik::query()
                        ->where('is_active', true)
                        ->exists();

                    if (
                        $activeInFile === 0 &&
                        ! $hasActiveInDatabase
                    ) {
                        throw ValidationException::withMessages([
                            'file' =>
                                'Import harus menyediakan satu tahun akademik aktif karena belum ada periode aktif di database.',
                        ]);
                    }

                    // Jika file memiliki periode aktif,
                    // nonaktifkan periode lama terlebih dahulu.
                    if ($activeInFile === 1) {
                        TahunAkademik::query()->update([
                            'is_active' => false,
                        ]);
                    }

                    // Simpan seluruh data.
                    foreach ($result['normalized_rows'] as $row) {
                        TahunAkademik::create($row);
                }

                    return count($result['normalized_rows']);
                }
            );

            return ApiResponse::message(
                'Import tahun akademik berhasil.',
                [
                    'imported_rows' => $importedCount,
                ]
            );
        } catch (ValidationException $exception) {
            return ApiResponse::message(
                'Import tahun akademik dibatalkan.',
                [
                    'errors' => $exception->errors(),
                ],
                422
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::message(
                'Terjadi kesalahan saat mengimpor tahun akademik.',
                null,
                500
            );
        }
    }

    /**
     * Membaca dan memvalidasi seluruh isi Excel.
     *
     * Digunakan kembali oleh preview dan import.
     */
    private function readAndValidate(string $filePath): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');

            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($filePath);
        } catch (ReaderException | SpreadsheetException $exception) {
            throw ValidationException::withMessages([
                'file' =>
                    'File Excel tidak dapat dibaca. Gunakan template resmi.',
            ]);
        }

        try {
            if ($spreadsheet->getSheetCount() !== 1) {
                throw ValidationException::withMessages([
                    'file' =>
                        'File hanya boleh memiliki satu worksheet.',
                ]);
            }

            $sheet = $spreadsheet->getSheet(0);

            // Validasi header.
            $headers = [];

            foreach (range('A', 'D') as $column) {
                $headers[] = strtolower(
                    trim(
                        (string) $sheet
                            ->getCell("{$column}1")
                            ->getValue()
                    )
                );
            }

            if (
                $headers !== self::HEADERS ||
                trim(
                    (string) $sheet
                        ->getCell('E1')
                        ->getValue()
                ) !== ''
            ) {
                throw ValidationException::withMessages([
                    'file' =>
                        'Header Excel tidak sesuai template. Gunakan tahun_mulai, tahun_selesai, semester, is_active.',
                ]);
            }

            $highestRow = $sheet->getHighestDataRow();

            if ($highestRow > self::MAX_ROWS + 1) {
                throw ValidationException::withMessages([
                    'file' =>
                        'Maksimal 1000 baris data dalam satu file.',
                ]);
            }

            $rows = [];

            $occurrences = [];

            // Baca seluruh baris.
            for ($number = 2; $number <= $highestRow; $number++) {
                $cells = [];
                $values = [];

                foreach (range('A', 'D') as $column) {
                    $cell = $sheet->getCell(
                        "{$column}{$number}"
                    );

                    $cells[] = $cell;

                    $raw = $cell->getValue();

                    $values[] = is_scalar($raw)
                        ? trim((string) $raw)
                        : '';
                }

                // Abaikan baris kosong.
                if (
                    count(
                        array_filter(
                            $values,
                            fn ($value) => $value !== ''
                        )
                    ) === 0
                ) {
                    continue;
                }

                [
                    $tahunMulai,
                    $tahunSelesai,
                    $semester,
                    $isActive,
                ] = $values;

                $semester = strtoupper($semester);

                $data = [
                    'tahun_mulai' => $tahunMulai,
                    'tahun_selesai' => $tahunSelesai,
                    'semester' => $semester,
                    'is_active' => $isActive,
                ];

                $validator = Validator::make(
                    $data,
                    [
                        'tahun_mulai' => [
                            'required',
                            'integer',
                            'digits:4',
                        ],
                        'tahun_selesai' => [
                            'required',
                            'integer',
                            'digits:4',
                        ],
                        'semester' => [
                            'required',
                            'in:GANJIL,GENAP',
                        ],
                        'is_active' => [
                            'required',
                            'in:0,1',
                        ],
                    ],
                    [
                        'tahun_mulai.digits' =>
                            'Tahun mulai harus terdiri dari 4 digit.',
                        'tahun_selesai.digits' =>
                            'Tahun selesai harus terdiri dari 4 digit.',
                        'semester.in' =>
                            'Semester harus GANJIL atau GENAP.',
                        'is_active.in' =>
                            'Status aktif harus bernilai 1 atau 0.',
                    ]
                );

                $errors = $validator->errors()->all();
                // Tahun selesai tidak boleh lebih kecil dari tahun mulai.
if (
    preg_match('/^\d{4}$/', $tahunMulai) &&
    preg_match('/^\d{4}$/', $tahunSelesai) &&
    (int) $tahunSelesai < (int) $tahunMulai
) {
    $errors[] =
        'Tahun selesai tidak boleh lebih kecil dari tahun mulai.';
}
                // Tolak formula Excel.
                foreach ($cells as $cell) {
                    if (
                        $cell->getDataType() === DataType::TYPE_FORMULA
                    ) {
                        $errors[] =
                            'Formula Excel tidak diperbolehkan.';

                        break;
                    }
                }

                // Catat kombinasi untuk mendeteksi duplikasi.
                $key = implode('|', [
                    $tahunMulai,
                    $tahunSelesai,
                    $semester,
                ]);

                $occurrences[$key][] = $number;

                $rows[] = [
                    'row' => $number,
                    'tahun_mulai' => $tahunMulai,
                    'tahun_selesai' => $tahunSelesai,
                    'semester' => $semester,
                    'is_active' => $isActive,
                    'valid' => false,
                    'errors' => $errors,
                ];
            }

            if ($rows === []) {
                throw ValidationException::withMessages([
                    'file' =>
                        'File Excel belum berisi data tahun akademik.',
                ]);
            }

            // Periksa duplikasi dalam file.
            // Periksa duplikasi dalam file.
$validRows = 0;
$activeCount = collect($rows)
    ->filter(fn ($row) => $row['is_active'] === '1')
    ->count();

            foreach ($rows as &$row) {
                $key = implode('|', [
                    $row['tahun_mulai'],
                    $row['tahun_selesai'],
                    $row['semester'],
                ]);

                if (count($occurrences[$key]) > 1) {
                    $numbers = implode(
                        ', ',
                        $occurrences[$key]
                    );

                    $row['errors'][] =
                        "Kombinasi tahun akademik dan semester duplikat pada baris {$numbers}.";
                }

                // Periksa duplikasi database hanya untuk
                // kombinasi yang formatnya sudah valid.
                if (
                    $row['errors'] === [] &&
                    TahunAkademik::query()
                        ->where('tahun_mulai', $row['tahun_mulai'])
                        ->where('tahun_selesai', $row['tahun_selesai'])
                        ->where('semester', $row['semester'])
                        ->exists()
                ) {
                    $row['errors'][] =
                        'Kombinasi tahun akademik dan semester sudah terdaftar di database.';
                }

                if (
    $activeCount > 1 &&
    $row['is_active'] === '1'
) {
    $row['errors'][] =
        'File Excel hanya boleh memiliki satu tahun akademik aktif.';
}

                $row['valid'] = $row['errors'] === [];

                if ($row['valid']) {
                    $validRows++;
                }
            }

            unset($row);

            // Normalisasi data yang akan disimpan.
            $normalizedRows = [];

            foreach ($rows as $row) {
                $normalizedRows[] = [
                    'tahun_mulai' => (int) $row['tahun_mulai'],
                    'tahun_selesai' => (int) $row['tahun_selesai'],
                    'semester' => $row['semester'],
                    'is_active' => $row['is_active'] === '1',
                ];
            }

            return [
                'rows' => $rows,
                'normalized_rows' => $normalizedRows,
                'valid_rows' => $validRows,
                'invalid_rows' => count($rows) - $validRows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();

            unset($spreadsheet);
        }
    }
}
