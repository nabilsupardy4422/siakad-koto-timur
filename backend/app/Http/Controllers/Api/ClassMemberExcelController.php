<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnggotaKelas;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\User;
use App\Models\WaliKelas;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class ClassMemberExcelController extends Controller
{
    private const MAX_ROWS = 1000;

    private const HEADERS = [
        'username',
        'nisn',
        'nipd',
        'nis',
        'nama_lengkap',
        'jenis_kelamin',
        'tanggal_lahir',
        'tempat_lahir',
        'no_telepon',
        'alamat',
    ];

    /**
     * Download template Excel Anggota Kelas.
     */
    /**
 * Download template Excel Anggota Kelas.
 */
public function template()
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setTitle('Anggota Kelas');

    // Header template
    foreach (self::HEADERS as $index => $header) {
        $column = chr(65 + $index);
        $sheet->setCellValue("{$column}1", $header);
    }

    // Styling header mengikuti template Data Siswa
    $sheet->getStyle('A1:J1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 11,
            'color' => [
                'rgb' => 'FFFFFF',
            ],
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => '4472C4',
            ],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ],
        ],
    ]);

    // Tinggi header
    $sheet->getRowDimension(1)->setRowHeight(30);

    // Lebar kolom
    $widths = [
        'A' => 18,
        'B' => 20,
        'C' => 18,
        'D' => 18,
        'E' => 30,
        'F' => 18,
        'G' => 18,
        'H' => 22,
        'I' => 20,
        'J' => 35,
    ];

    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    // Freeze header dan aktifkan filter
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:J1001');

    // Format identitas dan nomor telepon sebagai teks
    foreach (['A', 'B', 'C', 'D', 'E', 'H', 'I', 'J'] as $column) {
        $sheet->getStyle("{$column}2:{$column}1001")
            ->getNumberFormat()
            ->setFormatCode('@');
    }

    // Format tanggal lahir
    $sheet->getStyle('G2:G1001')
        ->getNumberFormat()
        ->setFormatCode('dd/mm/yyyy');

    // Validasi jenis kelamin
    $validation = $sheet->getCell('F2')
        ->getDataValidation();

    $validation->setType(
        \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST
    );

    $validation->setErrorStyle(
        \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP
    );

    $validation->setAllowBlank(true);
    $validation->setShowInputMessage(true);
    $validation->setShowErrorMessage(true);
    $validation->setShowDropDown(true);

    $validation->setErrorTitle('Jenis kelamin tidak valid');
    $validation->setError('Isi dengan L atau P.');
    $validation->setPromptTitle('Jenis Kelamin');
    $validation->setPrompt('Pilih L untuk laki-laki atau P untuk perempuan.');

    $validation->setFormula1('"L,P"');

    for ($row = 2; $row <= 1001; $row++) {
        $sheet->getCell("F{$row}")->setDataValidation(clone $validation);
    }

    return response()->streamDownload(
        function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);

            $writer->save('php://output');

            $spreadsheet->disconnectWorksheets();
        },
        'template_anggota_kelas.xlsx',
        [
            'Content-Type' =>
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]
    );
}

    /**
     * Preview dan validasi Excel Anggota Kelas.
     */
    public function preview(
        Request $request,
        Kelas $class
    ): JsonResponse {
        $user = $request->user();

        if (
            !$user ||
            !$this->canManageClassMembers($class, $user)
        ) {
            return ApiResponse::message(
                'Anda tidak memiliki akses untuk mengimpor anggota kelas ini.',
                null,
                403
            );
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:2048',
            ],
        ]);

        $result = $this->readAndValidate(
            $validated['file']->getRealPath(),
            $class
        );

        if (isset($result['error'])) {
            return ApiResponse::message(
                $result['error'],
                $result['data'] ?? null,
                422
            );
        }

        return ApiResponse::message(
            'Preview import Anggota Kelas berhasil dibuat.',
            [
                'summary' => $result['summary'],
                'rows' => $result['rows'],
            ]
        );
    }

    /**
     * Import anggota kelas.
     */
    public function import(
        Request $request,
        Kelas $class
    ): JsonResponse {
        $user = $request->user();

        if (
            !$user ||
            !$this->canManageClassMembers($class, $user)
        ) {
            return ApiResponse::message(
                'Anda tidak memiliki akses untuk mengimpor anggota kelas ini.',
                null,
                403
            );
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:2048',
            ],
        ]);

        // Baca ulang file agar validasi dilakukan kembali
        // ketika pengguna mengonfirmasi proses import.
        $result = $this->readAndValidate(
            $validated['file']->getRealPath(),
            $class
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
                [
                    'summary' => $result['summary'],
                    'rows' => $result['rows'],
                ],
                422
            );
        }

        $imported = 0;
        $skipped = 0;
        $rejected = 0;
        $details = [];

        try {
            DB::transaction(function () use (
                $result,
                $class,
                &$imported,
                &$skipped,
                &$rejected,
                &$details
            ): void {
                foreach ($result['rows'] as $row) {
                    // Hanya baris dengan status Valid yang diproses.
                    if ($row['status'] !== 'Valid') {
                        if ($row['status'] === 'Sudah di kelas') {
                            $skipped++;
                        } else {
                            $rejected++;
                        }

                        $details[] = [
                            'row' => $row['row'],
                            'nisn' => $row['nisn'],
                            'nama' => $row['nama_excel'],
                            'status' => $row['status'],
                            'keterangan' => $row['keterangan'],
                        ];

                        continue;
                    }

                    // Kunci data siswa agar proses penambahan anggota
                    // melalui controller ini dan ClassMemberController
                    // dapat saling menunggu untuk siswa yang sama.
                    $student = Siswa::query()
                        ->whereKey($row['siswa_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$student) {
                        $rejected++;

                        $details[] = [
                            'row' => $row['row'],
                            'nisn' => $row['nisn'],
                            'nama' => $row['nama_excel'],
                            'status' => 'Ditolak',
                            'keterangan' => 'Data siswa tidak lagi ditemukan.',
                        ];

                        continue;
                    }

                    // Periksa kembali identitas siswa.
                    $identityMatches = true;

                    if (
                        $row['nisn'] !== '' &&
                        $student->nisn !== $row['nisn']
                    ) {
                        $identityMatches = false;
                    }

                    if (
                        $row['nipd'] !== '' &&
                        $student->nipd !== $row['nipd']
                    ) {
                        $identityMatches = false;
                    }

                    $normalizeName = fn ($value) => mb_strtolower(
                        preg_replace('/\s+/', ' ', trim((string) $value))
                    );

                    if (
                        $normalizeName($student->nama_lengkap) !==
                        $normalizeName($row['nama_excel'])
                    ) {
                        $identityMatches = false;
                    }

                    if (!$identityMatches) {
                        $rejected++;

                        $details[] = [
                            'row' => $row['row'],
                            'nisn' => $row['nisn'],
                            'nama' => $row['nama_excel'],
                            'status' => 'Ditolak',
                            'keterangan' => 'Identitas siswa berubah atau tidak sesuai. Silakan preview ulang.',
                        ];

                        continue;
                    }

                    // Periksa keanggotaan aktif pada tahun akademik
                    // yang sama dengan kelas tujuan.
                    $activeMembership = AnggotaKelas::query()
                        ->where('siswa_id', $student->id)
                        ->whereDate(
                            'tanggal_mulai',
                            '<=',
                            Carbon::today()
                        )
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('tanggal_selesai')
                                ->orWhereDate(
                                    'tanggal_selesai',
                                    '>=',
                                    Carbon::today()
                                );
                        })
                        ->whereHas(
                            'kelas',
                            function (Builder $query) use ($class): void {
                                $query->where(
                                    'tahun_akademik_id',
                                    $class->tahun_akademik_id
                                );
                            }
                        )
                        ->with('kelas')
                        ->first();

                    if ($activeMembership) {
                        if (
                            (int) $activeMembership->kelas_id ===
                            (int) $class->id
                        ) {
                            $skipped++;

                            $details[] = [
                                'row' => $row['row'],
                                'nisn' => $row['nisn'],
                                'nama' => $row['nama_excel'],
                                'status' => 'Sudah di kelas',
                                'keterangan' => 'Siswa sudah menjadi anggota kelas tujuan.',
                            ];
                        } else {
                            $rejected++;

                            $details[] = [
                                'row' => $row['row'],
                                'nisn' => $row['nisn'],
                                'nama' => $row['nama_excel'],
                                'status' => 'Di kelas lain',
                                'keterangan' => 'Siswa sedang aktif di kelas lain pada tahun akademik yang sama.',
                            ];
                        }

                        continue;
                    }

                    // Tambahkan keanggotaan baru.
                    // Tidak membuat atau mengubah profil siswa.
                    AnggotaKelas::create([
                        'kelas_id' => $class->id,
                        'siswa_id' => $student->id,
                        'tanggal_mulai' => Carbon::today(),
                        'tanggal_selesai' => null,
                    ]);

                    $imported++;

                    $details[] = [
                        'row' => $row['row'],
                        'nisn' => $student->nisn,
                        'nama' => $student->nama_lengkap,
                        'status' => 'Berhasil diimpor',
                        'keterangan' => 'Siswa berhasil ditambahkan ke kelas.',
                    ];
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            return ApiResponse::message(
                'Import Anggota Kelas gagal diproses. Tidak ada perubahan data yang disimpan.',
                null,
                500
            );
        }

        return ApiResponse::message(
            'Proses import Anggota Kelas selesai.',
            [
                'summary' => [
                    'total_rows' => $result['summary']['total_rows'],
                    'imported_rows' => $imported,
                    'skipped_rows' => $skipped,
                    'rejected_rows' => $rejected,
                ],
                'details' => $details,
            ]
        );
    }

    /**
     * Memeriksa hak akses pengelolaan anggota kelas.
     */
    private function canManageClassMembers(
        Kelas $class,
        User $user
    ): bool {
        $role = $user->role?->code;

        if ($role === 'TU') {
            return true;
        }

        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        return $this->hasEffectiveHomeroomAssignment(
            $class,
            $guruId,
            Carbon::today()
        );
    }

    /**
     * Memastikan guru merupakan wali kelas yang masih aktif.
     */
    private function hasEffectiveHomeroomAssignment(
        Kelas $class,
        int $guruId,
        Carbon $date
    ): bool {
        return WaliKelas::query()
            ->where('guru_id', $guruId)
            ->where('kelas_id', $class->id)
            ->where(
                'tahun_akademik_id',
                $class->tahun_akademik_id
            )
            ->whereDate('tanggal_mulai', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query
                    ->whereNull('tanggal_selesai')
                    ->orWhereDate(
                        'tanggal_selesai',
                        '>=',
                        $date
                    );
            })
            ->exists();
    }

    /**
     * Membaca dan memvalidasi isi Excel.
     */
    private function readAndValidate(
        string $path,
        Kelas $class
    ): array {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($path);
        } catch (\Throwable $exception) {
            return [
                'error' => 'File Excel tidak dapat dibaca. Gunakan template resmi Anggota Kelas.',
            ];
        }

        try {
            // Pastikan hanya ada satu worksheet.
            if ($spreadsheet->getSheetCount() !== 1) {
                return [
                    'error' => 'File hanya boleh memiliki satu worksheet.',
                ];
            }

            $sheet = $spreadsheet->getSheet(0);

            // Periksa header Excel.
            $headers = [];

            foreach (range('A', 'J') as $column) {
                $headers[] = strtolower(trim(
                    (string) $sheet->getCell("{$column}1")->getValue()
                ));
            }

            if (
                $headers !== self::HEADERS ||
                trim((string) $sheet->getCell('K1')->getValue()) !== ''
            ) {
                return [
                    'error' => 'Header Excel tidak sesuai template Anggota Kelas.',
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

            // Membaca data Excel.
            for (
                $rowNumber = 2;
                $rowNumber <= $highestRow;
                $rowNumber++
            ) {
                $values = [];
                $hasFormula = false;

                foreach (range('A', 'J') as $column) {
                    $cell = $sheet->getCell("{$column}{$rowNumber}");

                    if (
                        $cell->getDataType() ===
                        DataType::TYPE_FORMULA
                    ) {
                        $hasFormula = true;
                    }

                    $values[] = trim(
                        (string) $cell->getFormattedValue()
                    );
                }

                // Lewati baris kosong.
                if (
                    count(array_filter(
                        $values,
                        fn ($value) => $value !== ''
                    )) === 0
                ) {
                    continue;
                }

                $rawRows[] = [
                    'row' => $rowNumber,
                    'nisn' => $values[1] ?? '',
                    'nipd' => $values[2] ?? '',
                    'nama_lengkap' => $values[4] ?? '',
                    'formula_error' => $hasFormula,
                ];
            }

            if (count($rawRows) === 0) {
                return [
                    'error' => 'File Excel belum memiliki data Anggota Kelas.',
                ];
            }

            // Ambil seluruh NISN dan NIPD.
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

            $studentsByNisn = Siswa::query()
                ->whereIn('nisn', $nisns)
                ->get()
                ->keyBy('nisn');

            $studentsByNipd = Siswa::query()
                ->whereIn('nipd', $nipds)
                ->get()
                ->keyBy('nipd');

            // Gabungkan ID siswa yang ditemukan.
            $studentIds = $studentsByNisn
                ->merge($studentsByNipd)
                ->pluck('id')
                ->unique()
                ->values();

            // Ambil keanggotaan aktif pada tahun akademik kelas tujuan.
            $activeMemberships = AnggotaKelas::query()
                ->whereIn('siswa_id', $studentIds)
                ->whereDate(
                    'tanggal_mulai',
                    '<=',
                    Carbon::today()
                )
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('tanggal_selesai')
                        ->orWhereDate(
                            'tanggal_selesai',
                            '>=',
                            Carbon::today()
                        );
                })
                ->whereHas(
                    'kelas',
                    function (Builder $query) use ($class): void {
                        $query->where(
                            'tahun_akademik_id',
                            $class->tahun_akademik_id
                        );
                    }
                )
                ->get()
                ->keyBy('siswa_id');

            $seenStudents = [];
            $rows = [];

            foreach ($rawRows as $row) {
                $errors = [];
                $student = null;

                $nisn = $row['nisn'];
                $nipd = $row['nipd'];
                $name = $row['nama_lengkap'];

                if ($row['formula_error']) {
                    $errors[] = 'Formula Excel tidak diperbolehkan.';
                }

                if ($nisn === '' && $nipd === '') {
                    $errors[] = 'NISN atau NIPD wajib diisi.';
                }

                if ($name === '') {
                    $errors[] = 'Nama lengkap wajib diisi.';
                }

                // NISN merupakan identitas utama.
                if ($nisn !== '') {
                    $student = $studentsByNisn->get($nisn);

                    if (!$student) {
                        $errors[] = 'NISN tidak ditemukan.';
                    }
                } elseif ($nipd !== '') {
                    // NIPD hanya digunakan jika NISN kosong.
                    $student = $studentsByNipd->get($nipd);

                    if (!$student) {
                        $errors[] = 'NIPD tidak ditemukan.';
                    }
                }

                // Jika NISN dan NIPD diberikan,
                // keduanya harus menunjuk siswa yang sama.
                if ($student && $nisn !== '' && $nipd !== '') {
                    $studentByNipd = $studentsByNipd->get($nipd);

                    if (
                        !$studentByNipd ||
                        (int) $studentByNipd->id !== (int) $student->id
                    ) {
                        $errors[] = 'NISN dan NIPD tidak merujuk pada siswa yang sama.';
                    }
                }

                // Cocokkan nama dengan database.
                if ($student && $name !== '') {
                    $normalizeName = fn ($value) => mb_strtolower(
                        preg_replace('/\s+/', ' ', trim((string) $value))
                    );

                    if (
                        $normalizeName($name) !==
                        $normalizeName($student->nama_lengkap)
                    ) {
                        $errors[] = 'Nama tidak sesuai dengan data siswa di database.';
                    }
                }

                // Periksa duplikat siswa dalam file.
                $isDuplicate = false;

                if ($student) {
                    $isDuplicate = isset($seenStudents[$student->id]);

                    $seenStudents[$student->id] = true;
                }

                // Tentukan status.
                if ($isDuplicate) {
                    $status = 'Duplikat';
                    $description = 'Siswa muncul lebih dari sekali dalam file Excel.';
                } elseif (
                    !$student &&
                    (
                        ($nisn !== '' && !$studentsByNisn->has($nisn)) ||
                        ($nisn === '' && $nipd !== '' && !$studentsByNipd->has($nipd))
                    )
                ) {
                    $status = 'Tidak ditemukan';
                    $description = implode(' ', $errors);
                } elseif (count($errors) > 0) {
                    $status = 'Konflik identitas';
                    $description = implode(' ', $errors);
                } elseif ($student) {
                    $membership = $activeMemberships->get($student->id);

                    if ($membership) {
                        if (
                            (int) $membership->kelas_id ===
                            (int) $class->id
                        ) {
                            $status = 'Sudah di kelas';
                            $description = 'Siswa sudah menjadi anggota kelas tujuan.';
                        } else {
                            $status = 'Di kelas lain';
                            $description = 'Siswa sedang aktif di kelas lain pada tahun akademik yang sama.';
                        }
                    } else {
                        $status = 'Valid';
                        $description = 'Siswa siap diimpor.';
                    }
                } else {
                    $status = 'Konflik identitas';
                    $description = implode(' ', $errors);
                }

                $rows[] = [
                    'row' => $row['row'],
                    'nisn' => $nisn,
                    'nipd' => $nipd,
                    'nama_excel' => $name,
                    'nama_database' => $student?->nama_lengkap,
                    'siswa_id' => $student?->id,
                    'valid' => $status === 'Valid',
                    'status' => $status,
                    'keterangan' => $description,
                ];
            }

            // Ringkasan hasil preview.
            $summary = [
                'total_rows' => count($rows),
                'valid_rows' => collect($rows)
                    ->where('status', 'Valid')
                    ->count(),

                'already_in_class' => collect($rows)
                    ->where('status', 'Sudah di kelas')
                    ->count(),

                'in_other_class' => collect($rows)
                    ->where('status', 'Di kelas lain')
                    ->count(),

                'not_found' => collect($rows)
                    ->where('status', 'Tidak ditemukan')
                    ->count(),

                'identity_conflict' => collect($rows)
                    ->where('status', 'Konflik identitas')
                    ->count(),

                'duplicate_rows' => collect($rows)
                    ->where('status', 'Duplikat')
                    ->count(),
            ];

            return [
                'summary' => $summary,
                'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
