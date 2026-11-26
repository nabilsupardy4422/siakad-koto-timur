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

        $sheet->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:D1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF173B62');
        $sheet->freezePane('A2');

        $sheet->setCellValue('A2', '2026');
        $sheet->setCellValue('B2', '2027');
        $sheet->setCellValue('C2', 'GANJIL');
        $sheet->setCellValue('D2', TahunAkademik::query()->exists() ? 0 : 1);

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
                    'file' => collect($result['rows'])->filter(fn ($row) => ! $row['valid'])->map(fn ($row) => 'Baris '.$row['row'].': '.implode(' ', $row['errors']))->values()->all(),
                ]);
            }

            $importedCount = DB::transaction(
                function () use ($result) {
                    // Kunci data untuk menjaga konsistensi
                    // status tahun akademik aktif.
                    $periods = TahunAkademik::lockForAdministrativeMutation();
                    $errors = $this->databaseErrors($result['rows'], $periods);
                    if ($errors !== []) {
                        throw ValidationException::withMessages(['file' => array_values($errors)[0][0]]);
                    }

                    // Simpan seluruh data.
                    foreach ($result['normalized_rows'] as $row) {
                        TahunAkademik::create($row);
                    }

                    return count($result['normalized_rows']);
                }, 3
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
                $sheet->getHighestDataColumn() !== 'D'
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
                if (preg_match('/^\d{4}$/', $tahunMulai) && preg_match('/^\d{4}$/', $tahunSelesai)
                    && ((int) $tahunMulai < 1000 || (int) $tahunSelesai !== (int) $tahunMulai + 1)) {
                    $errors[] = 'Tahun selesai harus satu tahun setelah tahun mulai.';
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

            $validRows = 0;
            $databaseErrors = $this->databaseErrors($rows, TahunAkademik::query()->get());
            foreach ($rows as &$row) {
                $key = implode('|', [$row['tahun_mulai'], $row['tahun_selesai'], $row['semester']]);
                if (count($occurrences[$key]) > 1) {
                    $numbers = implode(', ', $occurrences[$key]);
                    $row['errors'][] = "Kombinasi tahun akademik dan semester duplikat pada baris {$numbers}.";
                }
                $row['errors'] = array_merge($row['errors'], $databaseErrors[$row['row']] ?? []);
                $row['valid'] = $row['errors'] === [];
                $validRows += (int) $row['valid'];
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
    // Shared by preview and the locked write transaction; no silent activation.
    private function databaseErrors(array $rows, \Illuminate\Support\Collection $periods): array
    {
        $errors = [];
        $activeCount = collect($rows)->whereStrict('is_active', '1')->count();
        $hasActive = $periods->where('is_active', true)->count() === 1;
        $keys = $periods->mapWithKeys(fn ($period) => [
            "{$period->tahun_mulai}|{$period->tahun_selesai}|{$period->semester}" => true,
        ]);

        foreach ($rows as $row) {
            $key = implode('|', [(int) $row['tahun_mulai'], (int) $row['tahun_selesai'], $row['semester']]);
            if ($keys->has($key)) {
                $errors[$row['row']][] = 'Kombinasi tahun akademik dan semester sudah terdaftar di database.';
            }
            if ($periods->isEmpty() && $activeCount !== 1) {
                $errors[$row['row']][] = 'Import pertama harus memiliki tepat satu periode aktif (is_active = 1).';
            } elseif ($periods->isNotEmpty() && ! $hasActive) {
                $errors[$row['row']][] = 'Periode aktif belum konsisten. Aktifkan satu periode yang sudah tersedia sebelum mengimpor.';
            } elseif ($hasActive && $row['is_active'] === '1') {
                $errors[$row['row']][] = 'Gunakan is_active = 0. Aktivasi dilakukan terpisah setelah import.';
            }
        }

        return $errors;
    }
}
