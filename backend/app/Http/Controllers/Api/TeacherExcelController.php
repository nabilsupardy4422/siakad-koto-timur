<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guru;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class TeacherExcelController extends Controller
{
    private const MAX_ROWS = 1000;

    private const HEADERS = [
        'username',
        'nip',
        'nama_lengkap',
        'jenis_kelamin',
        'no_telepon',
        'alamat',
    ];

    public function template()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setTitle('Data Guru');

        foreach (self::HEADERS as $index => $header) {
            $column = chr(65 + $index);
            $sheet->setCellValue("{$column}1", $header);
        }

        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // NIP dan nomor telepon diperlakukan sebagai teks.
        $sheet->getStyle('B:B')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('E:E')->getNumberFormat()->setFormatCode('@');

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            },
            'template_data_guru.xlsx',
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

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

        $result = $this->readAndValidate($validated['file']->getRealPath());

        if (isset($result['error'])) {
            return ApiResponse::message(
                $result['error'],
                $result['data'] ?? null,
                422
            );
        }

        return ApiResponse::message(
            'Preview import Data Guru berhasil dibuat.',
            [
                'summary' => $result['summary'],
                'rows' => $result['rows'],
            ]
        );
    }

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

        $result = $this->readAndValidate($validated['file']->getRealPath());

        if (isset($result['error'])) {
            return ApiResponse::message(
                $result['error'],
                $result['data'] ?? null,
                422
            );
        }

        if ($result['summary']['valid_rows'] === 0) {
            return ApiResponse::message(
                'Tidak ada data Guru valid untuk diimpor.',
                $result,
                422
            );
        }

        if ($result['summary']['invalid_rows'] > 0) {
            return ApiResponse::message(
                'Import dibatalkan karena terdapat baris yang tidak valid. Perbaiki data terlebih dahulu.',
                [
                    'summary' => $result['summary'],
                    'rows' => $result['rows'],
                ],
                422
            );
        }

        try {
            DB::transaction(function () use ($result): void {
                $usernames = collect($result['rows'])
                    ->pluck('username')
                    ->all();

                $users = User::query()
                    ->with(['role', 'guru'])
                    ->whereIn('username', $usernames)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('username');

                $nips = collect($result['rows'])
                    ->pluck('nip')
                    ->all();

                $existingNips = Guru::query()
                    ->whereIn('nip', $nips)
                    ->lockForUpdate()
                    ->pluck('nip')
                    ->all();

                if (count($existingNips) > 0) {
                    throw new \RuntimeException(
                        'Terdapat NIP yang sudah digunakan. Silakan preview ulang file.'
                    );
                }

                foreach ($result['rows'] as $row) {
                    $user = $users->get($row['username']);

                    if (
                        !$user ||
                        !$user->role ||
                        $user->role->code !== 'GURU' ||
                        $user->account_status !== 'active' ||
                        $user->guru
                    ) {
                        throw new \RuntimeException(
                            "Akun {$row['username']} sudah tidak memenuhi ketentuan import. Silakan preview ulang."
                        );
                    }
                }

                foreach ($result['rows'] as $row) {
                    $user = $users->get($row['username']);

                    Guru::create([
                        'user_id' => $user->id,
                        'nip' => $row['nip'],
                        'nama_lengkap' => $row['nama_lengkap'],
                        'jenis_kelamin' => $row['jenis_kelamin'] ?: null,
                        'no_telepon' => $row['no_telepon'] ?: null,
                        'alamat' => $row['alamat'] ?: null,
                    ]);
                }
            });
        } catch (\RuntimeException $exception) {
            return ApiResponse::message(
                $exception->getMessage(),
                null,
                422
            );
        } catch (\Throwable $exception) {
            report($exception);

            return ApiResponse::message(
                'Import Data Guru gagal. Tidak ada data yang disimpan.',
                null,
                500
            );
        }

        return ApiResponse::message(
            'Data Guru berhasil diimpor.',
            [
                'imported_rows' => count($result['rows']),
            ]
        );
    }

    private function readAndValidate(string $path): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($path);
        } catch (ReaderException | SpreadsheetException $exception) {
            return [
                'error' => 'File Excel tidak dapat dibaca. Gunakan template resmi Data Guru.',
            ];
        }

        try {
            if ($spreadsheet->getSheetCount() !== 1) {
                return [
                    'error' => 'File hanya boleh memiliki satu worksheet.',
                ];
            }

            $sheet = $spreadsheet->getSheet(0);

            $headers = [];

            foreach (range('A', 'F') as $column) {
                $headers[] = strtolower(trim(
                    (string) $sheet->getCell("{$column}1")->getValue()
                ));
            }

            if (
                $headers !== self::HEADERS ||
                trim((string) $sheet->getCell('G1')->getValue()) !== ''
            ) {
                return [
                    'error' => 'Header Excel tidak sesuai template Data Guru.',
                    'data' => [
                        'expected_headers' => self::HEADERS,
                    ],
                ];
            }

            $highestRow = $sheet->getHighestDataRow();

            if ($highestRow > self::MAX_ROWS + 1) {
                return [
                    'error' => 'Maksimal 1000 baris data dalam satu file.',
                ];
            }

            $rawRows = [];
            $usernameOccurrences = [];
            $nipOccurrences = [];

            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $values = [];

                foreach (range('A', 'F') as $column) {
                    $cell = $sheet->getCell("{$column}{$rowNumber}");

                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $values['formula_error'] = true;
                    }

                    $values[] = trim((string) $cell->getFormattedValue());
                }

                // Lewati baris kosong.
                if (count(array_filter($values, fn ($value) => $value !== '')) === 0) {
                    continue;
                }

                $data = [
                    'username' => $values[0] ?? '',
                    'nip' => $values[1] ?? '',
                    'nama_lengkap' => $values[2] ?? '',
                    'jenis_kelamin' => strtoupper($values[3] ?? ''),
                    'no_telepon' => $values[4] ?? '',
                    'alamat' => $values[5] ?? '',
                ];

                $errors = [];

                if (isset($values['formula_error'])) {
                    $errors[] = 'Formula Excel tidak diperbolehkan.';
                }

                $validator = Validator::make($data, [
                    'username' => ['required', 'string', 'max:255'],
                    'nip' => ['required', 'string', 'max:50'],
                    'nama_lengkap' => ['required', 'string', 'max:255'],
                    'jenis_kelamin' => ['nullable', 'in:L,P'],
                    'no_telepon' => ['nullable', 'string', 'max:30'],
                    'alamat' => ['nullable', 'string'],
                ], [
                    'username.required' => 'Username wajib diisi.',
                    'nip.required' => 'NIP wajib diisi.',
                    'nip.max' => 'NIP maksimal 50 karakter.',
                    'nama_lengkap.required' => 'Nama lengkap wajib diisi.',
                    'jenis_kelamin.in' => 'Jenis kelamin hanya boleh L atau P.',
                ]);

                if ($validator->fails()) {
                    $errors = array_merge(
                        $errors,
                        $validator->errors()->all()
                    );
                }

                $username = $data['username'];
                $nip = $data['nip'];

                if ($username !== '') {
                    $usernameOccurrences[$username] =
                        ($usernameOccurrences[$username] ?? 0) + 1;

                    if ($usernameOccurrences[$username] > 1) {
                        $errors[] = 'Username duplikat dalam file Excel.';
                    }
                }

                if ($nip !== '') {
                    $nipOccurrences[$nip] =
                        ($nipOccurrences[$nip] ?? 0) + 1;

                    if ($nipOccurrences[$nip] > 1) {
                        $errors[] = 'NIP duplikat dalam file Excel.';
                    }
                }

                $rawRows[] = [
                    'row' => $rowNumber,
                    ...$data,
                    'errors' => array_values(array_unique($errors)),
                ];
            }

            if (count($rawRows) === 0) {
                return [
                    'error' => 'File Excel belum memiliki data Guru.',
                ];
            }

            $usernames = collect($rawRows)
                ->pluck('username')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $users = User::query()
                ->with(['role', 'guru'])
                ->whereIn('username', $usernames)
                ->get()
                ->keyBy('username');

            $nips = collect($rawRows)
                ->pluck('nip')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $existingNips = Guru::query()
                ->whereIn('nip', $nips)
                ->pluck('nip')
                ->all();

            $existingNips = array_flip($existingNips);

            $rows = [];

            foreach ($rawRows as $row) {
                $errors = $row['errors'];
                $user = $users->get($row['username']);

                if (!$user && $row['username'] !== '') {
                    $errors[] = 'Username tidak ditemukan.';
                } elseif ($user) {
                    if (!$user->role || $user->role->code !== 'GURU') {
                        $errors[] = 'Akun bukan pengguna dengan role GURU.';
                    }

                    if ($user->account_status !== 'active') {
                        $errors[] = 'Akun Guru tidak aktif.';
                    }

                    if ($user->guru) {
                        $errors[] = 'Akun sudah memiliki profil Guru.';
                    }
                }

                if (isset($existingNips[$row['nip']])) {
                    $errors[] = 'NIP sudah digunakan oleh Guru lain.';
                }

                $rows[] = [
                    'row' => $row['row'],
                    'username' => $row['username'],
                    'nip' => $row['nip'],
                    'nama_lengkap' => $row['nama_lengkap'],
                    'jenis_kelamin' => $row['jenis_kelamin'],
                    'no_telepon' => $row['no_telepon'],
                    'alamat' => $row['alamat'],
                    'valid' => count($errors) === 0,
                    'errors' => array_values(array_unique($errors)),
                ];
            }

            $validRows = collect($rows)->where('valid', true)->count();

            return [
                'summary' => [
                    'total_rows' => count($rows),
                    'valid_rows' => $validRows,
                    'invalid_rows' => count($rows) - $validRows,
                ],
                'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}