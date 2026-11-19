<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class UserImportPreviewController extends Controller
{
    private const MAX_ROWS = 1000;

    // Import massal hanya boleh Guru dan Siswa.
    // TU/Kepala Sekolah menggunakan prosedur khusus.
    private const ALLOWED_ROLES = [
        'GURU',
        'SISWA',
    ];

    private const HEADERS = [
        'username',
        'name',
        'email',
        'role_code',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        // =====================================
        // VALIDASI FILE
        // =====================================

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:2048',
            ],
        ]);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load(
                $validated['file']->getRealPath()
            );
        } catch (ReaderException | SpreadsheetException $exception) {
            return ApiResponse::message(
                'File Excel tidak dapat dibaca. Gunakan template .xlsx resmi.',
                null,
                422
            );
        }

        try {
            // =====================================
            // BATASI WORKSHEET
            // =====================================

            if ($spreadsheet->getSheetCount() !== 1) {
                return ApiResponse::message(
                    'File hanya boleh memiliki satu worksheet.',
                    null,
                    422
                );
            }

            $sheet = $spreadsheet->getSheet(0);

            // =====================================
            // VALIDASI HEADER
            // =====================================

            $headers = [];

            foreach (['A', 'B', 'C', 'D'] as $column) {
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
                return ApiResponse::message(
                    'Header Excel tidak sesuai template. Gunakan username, name, email, role_code.',
                    [
                        'expected_headers' => self::HEADERS,
                    ],
                    422
                );
            }

            // =====================================
            // BATASI JUMLAH BARIS
            // =====================================

            $highestRow = $sheet->getHighestDataRow();

            if ($highestRow > self::MAX_ROWS + 1) {
                return ApiResponse::message(
                    'Maksimal 1000 baris data dalam satu file.',
                    null,
                    422
                );
            }

            // =====================================
            // ROLE YANG TERSEDIA
            // =====================================

            $availableRoles = Role::query()
                ->whereIn(
                    'code',
                    self::ALLOWED_ROLES
                )
                ->pluck('code')
                ->all();

            $rows = [];

            $usernameOccurrences = [];
            $emailOccurrences = [];

            // =====================================
            // BACA DATA EXCEL
            // =====================================

            for (
                $number = 2;
                $number <= $highestRow;
                $number++
            ) {
                $cells = [];
                $values = [];

                foreach (['A', 'B', 'C', 'D'] as $column) {
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
                    $username,
                    $name,
                    $email,
                    $roleCode,
                ] = $values;

                // =================================
                // NORMALISASI
                // =================================

                $email = $email === ''
                    ? null
                    : strtolower($email);

                // GuRu / guru / GURU -> GURU
                $roleCode = strtoupper($roleCode);

                // =================================
                // VALIDASI KOLOM
                // =================================

                $validator = Validator::make(
                    compact(
                        'username',
                        'name',
                        'email',
                        'roleCode'
                    ),
                    [
                        'username' => [
                            'required',
                            'string',
                            'max:100',
                        ],

                        'name' => [
                            'required',
                            'string',
                            'max:255',
                        ],

                        'email' => [
                            'nullable',
                            'email',
                            'max:255',
                        ],

                        'roleCode' => [
                            'required',
                            Rule::in(self::ALLOWED_ROLES),
                        ],
                    ],
                    [
                        'roleCode.in' =>
                            'Import massal hanya menerima role GURU atau SISWA.',
                    ]
                );

                $errors = $validator
                    ->errors()
                    ->all();

                // =================================
                // TOLAK FORMULA EXCEL
                // =================================

                foreach ($cells as $cell) {
                    if (
                        $cell->getDataType() ===
                        DataType::TYPE_FORMULA
                    ) {
                        $errors[] =
                            'Formula Excel tidak diperbolehkan.';

                        break;
                    }
                }

                // =================================
                // USERNAME NUMERIK
                // =================================

                if (
                    is_numeric(
                        $cells[0]->getValue()
                    ) &&
                    ! is_string(
                        $cells[0]->getValue()
                    )
                ) {
                    $errors[] =
                        'Username numerik harus menggunakan format Text agar angka nol tidak hilang.';
                }

                // =================================
                // PERIKSA ROLE DATABASE
                // =================================

                if (
                    $roleCode !== '' &&
                    ! in_array(
                        $roleCode,
                        $availableRoles,
                        true
                    )
                ) {
                    $errors[] =
                        'Role tidak ditemukan atau tidak diizinkan untuk import massal.';
                }

                // =================================
                // CATAT KEMUNCULAN DATA
                // =================================

                $usernameKey = strtolower($username);

                if ($usernameKey !== '') {
                    $usernameOccurrences[
                        $usernameKey
                    ][] = $number;
                }

                if ($email !== null) {
                    $emailOccurrences[
                        $email
                    ][] = $number;
                }

                $rows[] = [
                    'row' => $number,
                    'username' => $username,
                    'name' => $name,
                    'email' => $email,
                    'role_code' => $roleCode,
                    'valid' => false,
                    'errors' => $errors,
                ];
            }

            // =====================================
            // FILE TANPA DATA
            // =====================================

            if ($rows === []) {
                return ApiResponse::message(
                    'File Excel belum berisi data pengguna.',
                    null,
                    422
                );
            }

            // =====================================
            // CEK USERNAME DALAM MYSQL
            // =====================================

            $existingUsernames = [];

            foreach (
                array_chunk(
                    array_keys($usernameOccurrences),
                    300
                ) as $chunk
            ) {
                $found = DB::table('users')
                    ->whereIn(
                        DB::raw('LOWER(username)'),
                        $chunk
                    )
                    ->pluck('username');

                foreach ($found as $value) {
                    $existingUsernames[
                        strtolower($value)
                    ] = true;
                }
            }

            // =====================================
            // CEK EMAIL DALAM MYSQL
            // =====================================

            $existingEmails = [];

            foreach (
                array_chunk(
                    array_keys($emailOccurrences),
                    300
                ) as $chunk
            ) {
                $found = DB::table('users')
                    ->whereIn(
                        DB::raw('LOWER(email)'),
                        $chunk
                    )
                    ->pluck('email');

                foreach ($found as $value) {
                    $existingEmails[
                        strtolower($value)
                    ] = true;
                }
            }

            // =====================================
            // FINALISASI STATUS SETIAP BARIS
            // =====================================

            $validRows = 0;

            foreach ($rows as &$row) {
                $usernameKey = strtolower(
                    $row['username']
                );

                $emailKey = $row['email'];

                // Duplikat username dalam Excel.
                if (
                    $usernameKey !== '' &&
                    count(
                        $usernameOccurrences[
                            $usernameKey
                        ] ?? []
                    ) > 1
                ) {
                    $numbers = implode(
                        ', ',
                        $usernameOccurrences[
                            $usernameKey
                        ]
                    );

                    $row['errors'][] =
                        "Username digunakan lebih dari sekali pada baris {$numbers}.";
                }

                // Duplikat email dalam Excel.
                if (
                    $emailKey !== null &&
                    count(
                        $emailOccurrences[
                            $emailKey
                        ] ?? []
                    ) > 1
                ) {
                    $numbers = implode(
                        ', ',
                        $emailOccurrences[
                            $emailKey
                        ]
                    );

                    $row['errors'][] =
                        "Email digunakan lebih dari sekali pada baris {$numbers}.";
                }

                // Username sudah terdaftar.
                if (
                    $usernameKey !== '' &&
                    isset(
                        $existingUsernames[
                            $usernameKey
                        ]
                    )
                ) {
                    $row['errors'][] =
                        'Username sudah terdaftar di SIAKAD.';
                }

                // Email sudah terdaftar.
                if (
                    $emailKey !== null &&
                    isset(
                        $existingEmails[$emailKey]
                    )
                ) {
                    $row['errors'][] =
                        'Email sudah terdaftar di SIAKAD.';
                }

                $row['valid'] =
                    $row['errors'] === [];

                if ($row['valid']) {
                    $validRows++;
                }
            }

            unset($row);

            // =====================================
            // RESPONSE PREVIEW
            // =====================================

            return ApiResponse::message(
                'Preview selesai. Belum ada akun yang disimpan.',
                [
                    'summary' => [
                        'total_rows' => count($rows),
                        'valid_rows' => $validRows,
                        'invalid_rows' =>
                            count($rows) - $validRows,
                    ],

                    'rows' => $rows,
                ]
            );
        } finally {
            // Bebaskan memori workbook.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }
}
