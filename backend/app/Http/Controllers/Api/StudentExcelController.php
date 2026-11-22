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
        'Nama', 'NIPD', 'JK', 'NISN', 'Tempat Lahir',
        'Tanggal Lahir', 'Alamat', 'HP', 'Rombel Saat Ini',
    ];
    private const HEADER_ROW = 1;
    private const DATA_START_ROW = 2;

   public function template()
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data Siswa');

    // Header mengikuti format file Excel resmi sekolah.
    $headers = [
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

    foreach ($headers as $index => $header) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $index + 1
        );

        $sheet->setCellValue($column . '1', $header);
    }

    // Format header.
    $sheet->getStyle('A1:I1')->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '4472C4'],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => 'D9E2F3'],
            ],
        ],
    ]);

    // Lebar kolom.
    $widths = [
        'A' => 30,
        'B' => 16,
        'C' => 12,
        'D' => 18,
        'E' => 20,
        'F' => 18,
        'G' => 35,
        'H' => 18,
        'I' => 22,
    ];

    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    // Pertahankan NIPD, NISN, dan nomor HP sebagai teks.
    $sheet->getStyle('B2:B1001')
        ->getNumberFormat()
        ->setFormatCode('@');

    $sheet->getStyle('D2:D1001')
        ->getNumberFormat()
        ->setFormatCode('@');

    $sheet->getStyle('H2:H1001')
        ->getNumberFormat()
        ->setFormatCode('@');

    // Format tanggal lahir.
    $sheet->getStyle('F2:F1001')
        ->getNumberFormat()
        ->setFormatCode('dd/mm/yyyy');

    // Validasi pilihan jenis kelamin.
    for ($row = 2; $row <= 1001; $row++) {
        $validation = $sheet->getCell("C{$row}")->getDataValidation();

        $validation->setType(
            \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST
        );

        $validation->setErrorStyle(
            \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP
        );

        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setFormula1('"L,P"');
        $validation->setPromptTitle('Jenis Kelamin');
        $validation->setPrompt('Pilih L untuk Laki-laki atau P untuk Perempuan.');
        $validation->setErrorTitle('Pilihan tidak valid');
        $validation->setError('Isi dengan L atau P.');
    }

    // Pengaturan tampilan.
    $sheet->getRowDimension(1)->setRowHeight(30);

    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:I1001');

    // Download file Excel.
    return response()->streamDownload(
        function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(
                $spreadsheet
            );

            $writer->save('php://output');

            $spreadsheet->disconnectWorksheets();
        },
        'template_data_siswa.xlsx',
        [
            'Content-Type' =>
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]
    );
}

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
        ]);

        $result = $this->readAndValidate($validated['file']->getRealPath());

        if (isset($result['error'])) {
            return ApiResponse::message($result['error'], $result['data'] ?? null, 422);
        }

        return ApiResponse::message('Preview Data Siswa berhasil dibuat.', $result);
    }

public function import(Request $request): JsonResponse
{
    $validated = $request->validate([
        'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
        'excluded_rows' => ['sometimes', 'array'],
        'excluded_rows.*' => ['integer', 'min:2'],
    ]);

    $result = $this->readAndValidate(
        $validated['file']->getRealPath(),
        $validated['excluded_rows'] ?? []
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

            // Ambil profil siswa yang sudah tersedia.
            $studentsByNisn = Siswa::query()
                ->with('user')
                ->whereIn('nisn', $nisns)
                ->lockForUpdate()
                ->get()
                ->keyBy('nisn');

            $studentsByNipd = Siswa::query()
                ->whereIn('nipd', $nipds)
                ->lockForUpdate()
                ->get()
                ->keyBy('nipd');

            // Ambil akun berdasarkan username/NISN.
            $existingUsers = User::query()
                ->with(['role', 'siswa'])
                ->whereIn('username', $nisns)
                ->lockForUpdate()
                ->get()
                ->keyBy('username');

            // Pemeriksaan ulang sebelum proses penyimpanan.
            foreach ($result['rows'] as $row) {
                $student = $studentsByNisn->get($row['nisn']);

                $nipdOwner = $row['nipd'] !== ''
                    ? $studentsByNipd->get($row['nipd'])
                    : null;

                $user = $existingUsers->get($row['nisn']);

                if (
                    $nipdOwner &&
                    (!$student || $nipdOwner->id !== $student->id)
                ) {
                    throw new \RuntimeException(
                        "NIPD {$row['nipd']} sudah digunakan oleh siswa lain. Silakan preview ulang."
                    );
                }

                if ($student && !$student->user) {
                    throw new \RuntimeException(
                        "Profil siswa dengan NISN {$row['nisn']} tidak memiliki akun pengguna."
                    );
                }

                if ($user) {
                    if ($user->role_id !== $studentRole->id) {
                        throw new \RuntimeException(
                            "Username {$row['nisn']} digunakan oleh akun dengan role selain SISWA."
                        );
                    }

                    // Jika profil sudah ada, pastikan akun tersebut
                    // memang terhubung dengan profil yang sesuai.
                    if (
                        $user->siswa &&
                        (!$student || $user->siswa->id !== $student->id)
                    ) {
                        throw new \RuntimeException(
                            "Akun dengan username {$row['nisn']} sudah terhubung dengan profil siswa lain."
                        );
                    }
                }
            }

            // Proses pembuatan dan pembaruan profil siswa.
            foreach ($result['rows'] as $row) {
                $student = $studentsByNisn->get($row['nisn']);

                $user = $existingUsers->get($row['nisn']);

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

                // Jika profil siswa sudah ada, perbarui datanya.
                if ($student) {
                    $student->update($studentData);

                    $updatedCount++;

                    continue;
                }

                // Jika akun sudah ada, gunakan akun tersebut.
                if (!$user) {
                    $user = User::create([
                        'role_id' => $studentRole->id,
                        'username' => $row['nisn'],
                        'name' => $row['nama_lengkap'],
                        'email' => null,
                        'password' => Str::random(48),
                        'account_status' => 'active',
                    ]);

                    $user->requires_activation = true;
                    $user->activated_at = null;
                    $user->save();
                }

                // Buat profil siswa menggunakan akun yang ditemukan
                // atau akun baru yang baru saja dibuat.
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


    private function readAndValidate(string $path, array $excludedRows = []): array
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
                return ['error' => 'File hanya boleh memiliki satu worksheet.'];
            }

            $sheet = $spreadsheet->getSheet(0);

            // 1. Validasi header Excel sekolah.
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

                $normalizedHeader = strtolower(preg_replace('/\s+/', ' ', $header));

                if ($normalizedHeader !== '') {
                    $headerMap[$normalizedHeader] = $column;
                }
            }

            $requiredHeaders = [];
            foreach (self::REQUIRED_HEADERS as $header) {
                $normalizedHeader = strtolower(preg_replace('/\s+/', ' ', trim($header)));
                if (!isset($headerMap[$normalizedHeader])) {
                    $requiredHeaders[] = $header;
                }
            }

            if (count($requiredHeaders) > 0) {
                return [
                    'error' => 'Format Excel tidak sesuai dengan file resmi sekolah.',
                    'data' => ['missing_headers' => $requiredHeaders],
                ];
            }

            // 2. Ambil posisi kolom berdasarkan header.
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

            // 3. Validasi jumlah baris.
            $highestRow = $sheet->getHighestDataRow();

            if ($highestRow < self::DATA_START_ROW) {
                return ['error' => 'File Excel belum memiliki data siswa.'];
            }

            if ($highestRow - self::DATA_START_ROW + 1 > self::MAX_ROWS) {
                return ['error' => 'Maksimal 1000 baris data dalam satu file.'];
            }

            // 4. Baca data siswa.
            $rawRows = [];
            $nisnOccurrences = [];
            $nipdOccurrences = [];
            $nameNisnOccurrences = [];
            $nameOccurrences = [];

            $excludedRows = array_values(array_unique(array_map('intval', $excludedRows)));

            for ($rowNumber = self::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
                if (in_array($rowNumber, $excludedRows, true)) {
                    continue;
                }

                $data = [];
                $hasFormula = false;

                foreach ($columns as $field => $column) {
                    $cell = $sheet->getCell("{$column}{$rowNumber}");

                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $hasFormula = true;
                    }

                    $data[$field] = trim((string) $cell->getFormattedValue());
                }

                if (count(array_filter($data, fn ($value) => $value !== '')) === 0) {
                    continue;
                }

                $errors = [];

                if ($hasFormula) {
                    $errors[] = 'Formula Excel tidak diperbolehkan.';
                }

                // Normalisasi jenis kelamin.
                $jenisKelamin = strtoupper(trim($data['jenis_kelamin']));

                if (in_array($jenisKelamin, ['LAKI-LAKI', 'LAKI LAKI', 'LAKI'], true)) {
                    $jenisKelamin = 'L';
                } elseif (in_array($jenisKelamin, ['PEREMPUAN', 'WANITA'], true)) {
                    $jenisKelamin = 'P';
                }

                // Normalisasi tanggal lahir.
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
                            $dateFormats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y'];
                            $parsedDate = null;

                            foreach ($dateFormats as $format) {
                                $date = \DateTime::createFromFormat('!' . $format, $tanggalLahir);

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

                // Validasi data.
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
                    $errors = array_merge($errors, $validator->errors()->all());
                }


                // Hitung kemunculan nama, kombinasi nama + NISN, dan NISN.
$nisn = trim($data['nisn']);
$nipd = trim($data['nipd']);

$normalizedName = mb_strtolower(
    preg_replace('/\s+/', ' ', trim($data['nama_lengkap']))
);

if ($normalizedName !== '') {
    $nameOccurrences[$normalizedName][] = [
        'row' => $rowNumber,
        'nisn' => $nisn,
    ];
}

if ($nisn !== '') {
    $duplicateKey = $normalizedName . '|' . $nisn;

    $nameNisnOccurrences[$duplicateKey] =
        ($nameNisnOccurrences[$duplicateKey] ?? 0) + 1;

    $nisnOccurrences[$nisn] =
        ($nisnOccurrences[$nisn] ?? 0) + 1;
}
                // Deteksi NIPD duplikat.
                if ($nipd !== '') {
                    $nipdOccurrences[$nipd] = ($nipdOccurrences[$nipd] ?? 0) + 1;

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
                return ['error' => 'File Excel belum memiliki data siswa.'];
            }


            // Tandai seluruh baris yang terdeteksi sebagai duplikat.
foreach ($rawRows as &$row) {
    $normalizedName = mb_strtolower(
        preg_replace('/\s+/', ' ', trim($row['nama_lengkap']))
    );

    $nisn = trim($row['nisn']);

    $duplicateKey = $normalizedName . '|' . $nisn;

    $sameNameRows = $nameOccurrences[$normalizedName] ?? [];

    $sameNameCount = count($sameNameRows);

    $hasEmptyNisn = collect($sameNameRows)->contains(
        fn ($item) => $item['nisn'] === ''
    );

    $sameNameNisnDuplicate = (
        $nisn !== '' &&
        ($nameNisnOccurrences[$duplicateKey] ?? 0) > 1
    );

    $potentialDuplicateByName = (
        $sameNameCount > 1 &&
        $hasEmptyNisn
    );

    if ($sameNameNisnDuplicate) {
        $row['errors'][] =
            'Duplikat: nama lengkap dan NISN sama dalam file Excel.';
    } elseif ($potentialDuplicateByName) {
        $row['errors'][] =
            'Potensi duplikat: nama lengkap sama dan terdapat NISN kosong dalam file Excel.';
    } elseif (
        $nisn !== '' &&
        ($nisnOccurrences[$nisn] ?? 0) > 1
    ) {
        $row['errors'][] =
            'Konflik: NISN sama tetapi nama lengkap berbeda.';
    }

    $row['errors'] = array_values(array_unique($row['errors']));
}
unset($row);

            // 5. Ambil data siswa yang sudah terdaftar.
            $nisns = collect($rawRows)->pluck('nisn')->filter()->unique()->values()->all();
            $nipds = collect($rawRows)->pluck('nipd')->filter()->unique()->values()->all();

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

            // 6. Periksa role SISWA dan konflik username.
    $studentRole = Role::query()
    ->where('code', 'SISWA')
    ->first();

$existingUsers = User::query()
    ->with(['role', 'siswa'])
    ->whereIn('username', $nisns)
    ->get()
    ->keyBy('username');

            // 7. Tentukan status setiap baris.
$rows = [];

foreach ($rawRows as $row) {
    $errors = $row['errors'];

    $student = $existingStudentsByNisn->get($row['nisn']);

    $nipdOwner = $row['nipd'] !== ''
        ? $existingStudentsByNipd->get($row['nipd'])
        : null;

    $user = $existingUsers->get($row['nisn']);

    $status = 'new';

    if ($student) {
        $status = 'updated';

        if ($nipdOwner && $nipdOwner->id !== $student->id) {
            $errors[] = 'NIPD sudah digunakan oleh siswa lain.';
        }

        if (!$student->user) {
            $errors[] = 'Profil siswa tidak memiliki akun pengguna.';
        }
    } else {
        if ($nipdOwner) {
            $errors[] = 'NIPD sudah digunakan oleh siswa lain.';
        }

        if ($user) {
            if (!$studentRole || $user->role_id !== $studentRole->id) {
                $errors[] = 'Username NISN sudah digunakan oleh akun dengan role selain SISWA.';
            } elseif ($user->siswa) {
                $errors[] = 'Akun siswa sudah terhubung dengan profil siswa lain.';
            }
        }

        if (!$studentRole) {
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



            // 8. Ringkasan preview.
            $validRows = collect($rows)->where('valid', true);

            return [
                'summary' => [
                    'total_rows' => count($rows),
                    'valid_rows' => $validRows->count(),
                    'invalid_rows' => count($rows) - $validRows->count(),
                    'new_students' => $validRows->where('status', 'new')->count(),
                    'updated_students' => $validRows->where('status', 'updated')->count(),
                ],
                'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
