<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use App\Support\ApiResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class StudentExcelController extends Controller
{
    private const MAX_ROWS = 1000;

    private const REQUIRED_HEADERS = [
        'Nama',
        'NIPD',
        'JK',
        'NISN',
        'Tempat Lahir',
        'Tanggal Lahir',
        'Alamat',
        'HP',
        'Rombel Saat Ini',
    ];

    private const HEADER_ROW = 5;
    private const DATA_START_ROW = 7;
    
public function template(): JsonResponse
{
    return ApiResponse::message(
        'Gunakan file Excel asli hasil ekspor Data Peserta Didik dari sekolah sebagai file import.',
        null,
        422
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

    $result = $this->readAndValidate(
        $validated['file']->getRealPath()
    );

    if (isset($result['error'])) {
        return ApiResponse::message(
            $result['error'],
            $result['data'] ?? null,
            422
        );
    }

    return ApiResponse::message(
        'Preview Data Siswa berhasil dibuat.',
        $result
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

    $result = $this->readAndValidate(
        $validated['file']->getRealPath()
    );

    if (isset($result['error'])) {
        return ApiResponse::message(
            $result['error'],
            $result['data'] ?? null,
            422
        );
    }

    if ($result['summary']['valid_rows'] === 0) {
        return ApiResponse::message(
            'Tidak ada data siswa valid untuk diimpor.',
            $result,
            422
        );
    }

    if ($result['summary']['invalid_rows'] > 0) {
        return ApiResponse::message(
            'Import dibatalkan karena terdapat data tidak valid. Perbaiki data terlebih dahulu.',
            [
                'summary' => $result['summary'],
                'rows' => $result['rows'],
            ],
            422
        );
    }

    $createdCount = 0;
    $updatedCount = 0;

    try {
        DB::transaction(function () use (
            $result,
            &$createdCount,
            &$updatedCount
        ): void {
            $studentRole = Role::query()
                ->where('code', 'SISWA')
                ->lockForUpdate()
                ->first();

            if (!$studentRole) {
                throw new \RuntimeException(
                    'Role SISWA tidak ditemukan.'
                );
            }

            $nisns = collect($result['rows'])
                ->pluck('nisn')
                ->all();

            $nipds = collect($result['rows'])
                ->pluck('nipd')
                ->filter()
                ->all();

            /*
             * Ambil siswa berdasarkan NISN.
             */
            $studentsByNisn = Siswa::query()
                ->with('user')
                ->whereIn('nisn', $nisns)
                ->lockForUpdate()
                ->get()
                ->keyBy('nisn');

            /*
             * Ambil siswa berdasarkan NIPD.
             */
            $studentsByNipd = Siswa::query()
                ->whereIn('nipd', $nipds)
                ->lockForUpdate()
                ->get()
                ->keyBy('nipd');

            /*
             * Pastikan username untuk siswa baru belum digunakan.
             */
            $existingUsernames = User::query()
                ->whereIn('username', $nisns)
                ->lockForUpdate()
                ->pluck('username')
                ->all();

            $existingUsernames = array_flip($existingUsernames);

            /*
             * Validasi ulang sebelum penyimpanan.
             */
            foreach ($result['rows'] as $row) {
                $student = $studentsByNisn->get($row['nisn']);

                $nipdOwner = $row['nipd'] !== ''
                    ? $studentsByNipd->get($row['nipd'])
                    : null;

                if (
                    $nipdOwner &&
                    (!$student || $nipdOwner->id !== $student->id)
                ) {
                    throw new \RuntimeException(
                        "NIPD {$row['nipd']} sudah digunakan oleh siswa lain. Silakan preview ulang."
                    );
                }

                if (!$student && isset($existingUsernames[$row['nisn']])) {
                    throw new \RuntimeException(
                        "Username {$row['nisn']} sudah digunakan. Silakan preview ulang."
                    );
                }

                if ($student && !$student->user) {
                    throw new \RuntimeException(
                        "Profil siswa dengan NISN {$row['nisn']} tidak memiliki akun pengguna."
                    );
                }
            }

            /*
             * Simpan data siswa.
             */
            foreach ($result['rows'] as $row) {
                $student = $studentsByNisn->get($row['nisn']);

                $studentData = [
                    'nisn' => $row['nisn'],
                    'nipd' => $row['nipd'] ?: null,
                    'nama_lengkap' => $row['nama_lengkap'],
                    'jenis_kelamin' => $row['jenis_kelamin'] ?: null,
                    'tanggal_lahir' => $row['tanggal_lahir'] ?: null,
                    'tempat_lahir' => $row['tempat_lahir'] ?: null,
                    'no_telepon' => $row['no_telepon'] ?: null,
                    'alamat' => $row['alamat'] ?: null,
                ];

                /*
                 * SISWA LAMA:
                 * Perbarui profil tanpa mengubah akun.
                 */
                if ($student) {
                    $student->update($studentData);

                    $updatedCount++;

                    continue;
                }

                /*
                 * SISWA BARU:
                 * Buat akun pengguna terlebih dahulu.
                 */
                $user = User::create([
                    'role_id' => $studentRole->id,
                    'username' => $row['nisn'],
                    'name' => $row['nama_lengkap'],
                    'email' => null,
                    'password' => Str::random(48),
                    'account_status' => 'active',
                ]);

                /*
                 * Wajib melakukan aktivasi akun.
                 */
                $user->requires_activation = true;
                $user->activated_at = null;
                $user->save();

                /*
                 * Buat profil siswa.
                 */
                Siswa::create([
                    'user_id' => $user->id,
                    ...$studentData,
                ]);

                $createdCount++;
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
            'Import Data Siswa gagal. Tidak ada perubahan yang disimpan.',
            null,
            500
        );
    }

    return ApiResponse::message(
        'Import Data Siswa berhasil.',
        [
            'total_imported' => $createdCount + $updatedCount,
            'new_students' => $createdCount,
            'updated_students' => $updatedCount,
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
            'error' => 'File Excel tidak dapat dibaca. Pastikan menggunakan file Excel asli dari sekolah.',
        ];
    }

    try {
        if ($spreadsheet->getSheetCount() !== 1) {
            return [
                'error' => 'File hanya boleh memiliki satu worksheet.',
            ];
        }

        $sheet = $spreadsheet->getSheet(0);

        /*
         * 1. Validasi header Excel sekolah.
         */
        $headerMap = [];

        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $sheet->getHighestDataColumn()
        );

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                $columnIndex
            );

            $header = trim((string) $sheet->getCell(
                "{$column}" . self::HEADER_ROW
            )->getFormattedValue());

            $normalizedHeader = strtolower(
                preg_replace('/\s+/', ' ', $header)
            );

            if ($normalizedHeader !== '') {
                $headerMap[$normalizedHeader] = $column;
            }
        }

        $requiredHeaders = [];

        foreach (self::REQUIRED_HEADERS as $header) {
            $normalizedHeader = strtolower(
                preg_replace('/\s+/', ' ', trim($header))
            );

            if (!isset($headerMap[$normalizedHeader])) {
                $requiredHeaders[] = $header;
            }
        }

        if (count($requiredHeaders) > 0) {
            return [
                'error' => 'Format Excel tidak sesuai dengan file resmi sekolah.',
                'data' => [
                    'missing_headers' => $requiredHeaders,
                ],
            ];
        }

        /*
         * 2. Ambil posisi kolom berdasarkan header.
         */
        $columns = [
            'nama_lengkap' => $headerMap['nama'],
            'nipd' => $headerMap['nipd'],
            'jenis_kelamin' => $headerMap['jk'],
            'nisn' => $headerMap['nisn'],
            'tempat_lahir' => $headerMap['tempat lahir'],
            'tanggal_lahir' => $headerMap['tanggal lahir'],
            'alamat' => $headerMap['alamat'],
            'no_telepon' => $headerMap['hp'],
            'rombel_saat_ini' => $headerMap['rombel saat ini'],
        ];

        /*
         * 3. Validasi jumlah baris.
         */
        $highestRow = $sheet->getHighestDataRow();

        if ($highestRow < self::DATA_START_ROW) {
            return [
                'error' => 'File Excel belum memiliki data siswa.',
            ];
        }

        if ($highestRow - self::DATA_START_ROW + 1 > self::MAX_ROWS) {
            return [
                'error' => 'Maksimal 1000 baris data dalam satu file.',
            ];
        }

        /*
         * 4. Baca data siswa.
         */
        $rawRows = [];
        $nisnOccurrences = [];
        $nipdOccurrences = [];

        for (
            $rowNumber = self::DATA_START_ROW;
            $rowNumber <= $highestRow;
            $rowNumber++
        ) {
            $data = [];
            $hasFormula = false;

            foreach ($columns as $field => $column) {
                $cell = $sheet->getCell("{$column}{$rowNumber}");

                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $hasFormula = true;
                }

                $value = trim((string) $cell->getFormattedValue());

                $data[$field] = $value;
            }

            if (count(array_filter(
                $data,
                fn ($value) => $value !== ''
            )) === 0) {
                continue;
            }

            $errors = [];

            if ($hasFormula) {
                $errors[] = 'Formula Excel tidak diperbolehkan.';
            }

            /*
             * Normalisasi jenis kelamin.
             */
            $jenisKelamin = strtoupper(trim($data['jenis_kelamin']));

            if (in_array($jenisKelamin, ['LAKI-LAKI', 'LAKI LAKI', 'LAKI'], true)) {
                $jenisKelamin = 'L';
            } elseif (in_array($jenisKelamin, ['PEREMPUAN', 'WANITA'], true)) {
                $jenisKelamin = 'P';
            }

            /*
             * Normalisasi tanggal lahir.
             */
            $tanggalLahir = $data['tanggal_lahir'];

            if ($tanggalLahir !== '') {
                $dateCell = $sheet->getCell(
                    "{$columns['tanggal_lahir']}{$rowNumber}"
                );

                try {
                    if (
                        is_numeric($dateCell->getValue()) &&
                        $dateCell->getDataType() !== DataType::TYPE_STRING
                    ) {
                        $tanggalLahir = ExcelDate::excelToDateTimeObject(
                            (float) $dateCell->getValue()
                        )->format('Y-m-d');
                    } else {
                        $dateFormats = [
                            'd/m/Y',
                            'd-m-Y',
                            'Y-m-d',
                            'd/m/y',
                            'd-m-y',
                        ];

                        $parsedDate = null;

                        foreach ($dateFormats as $format) {
                            $date = \DateTime::createFromFormat(
                                '!' . $format,
                                $tanggalLahir
                            );

                            if ($date && $date->format($format) === $tanggalLahir) {
                                $parsedDate = $date->format('Y-m-d');
                                break;
                            }
                        }

                        $tanggalLahir = $parsedDate ?? 'INVALID_DATE';
                    }
                } catch (\Throwable) {
                    $tanggalLahir = 'INVALID_DATE';
                }
            }

            $data['jenis_kelamin'] = $jenisKelamin;
            $data['tanggal_lahir'] = $tanggalLahir;

            /*
             * Validasi data.
             */
            $validator = Validator::make($data, [
                'nama_lengkap' => ['required', 'string', 'max:255'],
                'nipd' => ['nullable', 'string', 'max:50'],
                'nisn' => ['required', 'string', 'max:50'],
                'jenis_kelamin' => ['nullable', 'in:L,P'],
                'tempat_lahir' => ['nullable', 'string', 'max:100'],
                'tanggal_lahir' => ['nullable', 'date_format:Y-m-d'],
                'alamat' => ['nullable', 'string'],
                'no_telepon' => ['nullable', 'string', 'max:30'],
                'rombel_saat_ini' => ['nullable', 'string', 'max:100'],
            ], [
                'nama_lengkap.required' => 'Nama siswa wajib diisi.',
                'nisn.required' => 'NISN wajib diisi.',
                'nisn.max' => 'NISN maksimal 50 karakter.',
                'jenis_kelamin.in' => 'Jenis kelamin hanya boleh L atau P.',
                'tanggal_lahir.date_format' => 'Tanggal lahir tidak valid.',
            ]);

            if ($validator->fails()) {
                $errors = array_merge(
                    $errors,
                    $validator->errors()->all()
                );
            }

            /*
             * Deteksi NISN duplikat.
             */
            $nisn = $data['nisn'];
            $nipd = $data['nipd'];

            if ($nisn !== '') {
                $nisnOccurrences[$nisn] =
                    ($nisnOccurrences[$nisn] ?? 0) + 1;

                if ($nisnOccurrences[$nisn] > 1) {
                    $errors[] = 'NISN duplikat dalam file Excel.';
                }
            }

            /*
             * Deteksi NIPD duplikat.
             */
            if ($nipd !== '') {
                $nipdOccurrences[$nipd] =
                    ($nipdOccurrences[$nipd] ?? 0) + 1;

                if ($nipdOccurrences[$nipd] > 1) {
                    $errors[] = 'NIPD duplikat dalam file Excel.';
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
                'error' => 'File Excel belum memiliki data siswa.',
            ];
        }

        /*
         * 5. Ambil data siswa yang sudah terdaftar.
         */
        $nisns = collect($rawRows)
            ->pluck('nisn')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $nipds = collect($rawRows)
            ->pluck('nipd')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $existingStudentsByNisn = Siswa::query()
            ->with(['user.role'])
            ->whereIn('nisn', $nisns)
            ->get()
            ->keyBy('nisn');

        $existingStudentsByNipd = Siswa::query()
            ->with(['user.role'])
            ->whereIn('nipd', $nipds)
            ->get()
            ->keyBy('nipd');

        /*
         * 6. Periksa role SISWA dan konflik username.
         */
        $studentRoleExists = Role::query()
            ->where('code', 'SISWA')
            ->exists();

        $existingUsernames = User::query()
            ->whereIn('username', $nisns)
            ->pluck('username')
            ->all();

        $existingUsernames = array_flip($existingUsernames);

        /*
         * 7. Tentukan status setiap baris.
         */
        $rows = [];

        foreach ($rawRows as $row) {
            $errors = $row['errors'];

            $student = $existingStudentsByNisn->get($row['nisn']);

            $nipdOwner = $row['nipd'] !== ''
                ? $existingStudentsByNipd->get($row['nipd'])
                : null;

            $status = 'new';

            /*
             * Jika NISN sudah terdaftar, perbarui profil.
             */
            if ($student) {
                $status = 'updated';

                if (
                    $nipdOwner &&
                    $nipdOwner->id !== $student->id
                ) {
                    $errors[] = 'NIPD sudah digunakan oleh siswa lain.';
                }
            } else {
                /*
                 * Jika NISN baru tetapi NIPD sudah dimiliki siswa lain,
                 * data dianggap konflik.
                 */
                if ($nipdOwner) {
                    $errors[] = 'NIPD sudah digunakan oleh siswa lain.';
                }

                /*
                 * Cegah username bertabrakan dengan akun lain.
                 */
                if (isset($existingUsernames[$row['nisn']])) {
                    $errors[] = 'Username NISN sudah digunakan oleh akun lain.';
                }

                if (!$studentRoleExists) {
                    $errors[] = 'Role SISWA belum tersedia di database.';
                }
            }

            $rows[] = [
                'row' => $row['row'],
                'nisn' => $row['nisn'],
                'nipd' => $row['nipd'],
                'nama_lengkap' => $row['nama_lengkap'],
                'jenis_kelamin' => $row['jenis_kelamin'],
                'tanggal_lahir' => $row['tanggal_lahir'] === 'INVALID_DATE'
                    ? ''
                    : $row['tanggal_lahir'],
                'tempat_lahir' => $row['tempat_lahir'],
                'no_telepon' => $row['no_telepon'],
                'alamat' => $row['alamat'],
                'rombel_saat_ini' => $row['rombel_saat_ini'],
                'status' => $status,
                'valid' => count($errors) === 0,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        /*
         * 8. Ringkasan preview.
         */
        $validRows = collect($rows)
            ->where('valid', true);

        return [
            'summary' => [
                'total_rows' => count($rows),
                'valid_rows' => $validRows->count(),
                'invalid_rows' => count($rows) - $validRows->count(),
                'new_students' => $validRows
                    ->where('status', 'new')
                    ->count(),
                'updated_students' => $validRows
                    ->where('status', 'updated')
                    ->count(),
            ],
            'rows' => $rows,
        ];
    } finally {
        $spreadsheet->disconnectWorksheets();
        }
    }
}
