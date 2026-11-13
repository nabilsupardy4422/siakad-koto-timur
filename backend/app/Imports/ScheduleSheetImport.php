<?php

namespace App\Imports;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\TahunAkademik;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ScheduleSheetImport implements ToCollection, WithHeadingRow
{
    /**
     * Import data jadwal pelajaran dari worksheet pertama.
     */
    public function collection(Collection $rows): void
    {
        $validRows = [];
        $errors = [];

        Log::debug('ScheduleImport: worksheet diterima', [
            'total_rows' => $rows->count(),
        ]);

        /*
         * 1. Membaca dan memvalidasi setiap baris Excel.
         */
        foreach ($rows->values() as $index => $row) {
            $rowNumber = $index + 2;
            $rawRow = $row->toArray();

            $data = $this->normalizeRow($rawRow);

            Log::debug('ScheduleImport: pemeriksaan baris', [
                'row_number' => $rowNumber,
                'raw_keys' => array_keys($rawRow),
                'normalized_non_empty_fields' => array_keys(
                    array_filter(
                        $data,
                        fn ($value) => $value !== null && $value !== ''
                    )
                ),
            ]);

            // Lewati baris yang benar-benar kosong.
            if ($this->isEmptyRow($data)) {
                continue;
            }

            /*
             * 2. Validasi format dan struktur data.
             */
            $validator = Validator::make($data, [
                'tahun_akademik' => [
                    'required',
                    'regex:/^\d{4}\/\d{4}$/',
                ],
                'semester' => [
                    'required',
                    'in:GANJIL,GENAP',
                ],
                'tingkat' => [
                    'required',
                    'integer',
                ],
                'kelas' => [
                    'required',
                    'string',
                    'max:100',
                ],
                'kode_mapel' => [
                    'required',
                    'string',
                    'max:50',
                ],
                'nip_guru' => [
                    'required',
                    'string',
                    'max:50',
                ],
                'hari' => [
                    'required',
                    'in:SENIN,SELASA,RABU,KAMIS,JUMAT,SABTU,MINGGU',
                ],
                'jam_mulai' => [
                    'required',
                    'date_format:H:i',
                ],
                'jam_selesai' => [
                    'required',
                    'date_format:H:i',
                    'after:jam_mulai',
                ],
            ]);

            if ($validator->fails()) {
                $errors["baris_{$rowNumber}"] =
                    $validator->errors()->all();

                Log::warning('ScheduleImport: validasi gagal', [
                    'row_number' => $rowNumber,
                    'failed_fields' => array_keys(
                        $validator->failed()
                    ),
                ]);

                continue;
            }

            /*
             * 3. Mencari tahun akademik.
             */
            [$tahunMulai, $tahunSelesai] = array_map(
                'intval',
                explode('/', $data['tahun_akademik'])
            );

            $tahunAkademik = TahunAkademik::query()
                ->where('tahun_mulai', $tahunMulai)
                ->where('tahun_selesai', $tahunSelesai)
                ->where('semester', $data['semester'])
                ->get();

            if ($tahunAkademik->count() !== 1) {
                $errors["baris_{$rowNumber}"][] =
                    'Tahun akademik dan semester tidak ditemukan atau tidak unik.';

                continue;
            }

            $tahunAkademikId = $tahunAkademik->first()->id;

            Log::debug('ScheduleImport: parameter pencarian kelas', [
                'row_number' => $rowNumber,
                'tahun_akademik_id' => $tahunAkademikId,
                'tingkat' => $data['tingkat'],
                'kelas' => $data['kelas'],
            ]);

            /*
             * 4. Mencari kelas berdasarkan tahun akademik,
             * tingkat, dan nama kelas.
             */
            $kelas = Kelas::query()
                ->where('tahun_akademik_id', $tahunAkademikId)
                ->where('tingkat', $data['tingkat'])
                ->where('nama', $data['kelas'])
                ->get();

            if ($kelas->count() !== 1) {
                Log::warning('ScheduleImport: kelas tidak ditemukan atau tidak unik', [
                    'row_number' => $rowNumber,
                    'tahun_akademik_id' => $tahunAkademikId,
                    'tingkat' => $data['tingkat'],
                    'kelas' => $data['kelas'],
                    'jumlah_hasil' => $kelas->count(),
                ]);

                $errors["baris_{$rowNumber}"][] =
                    'Kelas tidak ditemukan atau tidak unik pada tahun akademik dan tingkat tersebut.';

                continue;
            }

            /*
             * 5. Mencari mata pelajaran berdasarkan kode.
             */
            $mapel = Mapel::query()
                ->where('kode', $data['kode_mapel'])
                ->get();

            if ($mapel->count() !== 1) {
                $errors["baris_{$rowNumber}"][] =
                    'Kode mata pelajaran tidak ditemukan atau tidak unik.';

                continue;
            }

            /*
             * 6. Mencari guru berdasarkan NIP.
             */
            $guru = Guru::query()
                ->where('nip', $data['nip_guru'])
                ->get();

            if ($guru->count() !== 1) {
                $errors["baris_{$rowNumber}"][] =
                    'NIP guru tidak ditemukan atau tidak unik.';

                continue;
            }

            /*
             * 7. Mapping data Excel menjadi foreign key.
             */
            $validRows[] = [
                'row_number' => $rowNumber,
                'data' => [
                    'tahun_akademik_id' => $tahunAkademikId,
                    'kelas_id' => $kelas->first()->id,
                    'mapel_id' => $mapel->first()->id,
                    'guru_id' => $guru->first()->id,
                    'hari' => $data['hari'],
                    'jam_mulai' => $data['jam_mulai'] . ':00',
                    'jam_selesai' => $data['jam_selesai'] . ':00',
                ],
            ];
        }

        Log::debug('ScheduleImport: hasil pemrosesan', [
            'total_rows' => $rows->count(),
            'valid_rows' => count($validRows),
            'error_rows' => count($errors),
        ]);

        /*
         * 8. Pemeriksaan bentrok dengan database
         * dan antarbaris dalam file Excel.
         */
        foreach ($validRows as $index => $item) {
            $data = $item['data'];
            $rowNumber = $item['row_number'];

            // Periksa bentrok dengan jadwal yang sudah tersimpan.
            if ($this->hasDatabaseConflict($data)) {
                $errors["baris_{$rowNumber}"][] =
                    'Jadwal bentrok dengan jadwal yang sudah tersimpan.';
            }

            // Periksa bentrok antarbaris Excel.
            foreach ($validRows as $otherIndex => $otherItem) {
                if ($index >= $otherIndex) {
                    continue;
                }

                if ($this->hasFileConflict(
                    $data,
                    $otherItem['data']
                )) {
                    $otherRow = $otherItem['row_number'];

                    $errors["baris_{$rowNumber}"][] =
                        "Jadwal bentrok dengan baris {$otherRow}.";

                    $errors["baris_{$otherRow}"][] =
                        "Jadwal bentrok dengan baris {$rowNumber}.";
                }
            }
        }

        /*
         * 9. Batalkan import jika ditemukan kesalahan.
         */
        if (!empty($errors)) {
            Log::warning('ScheduleImport: import dibatalkan', [
                'error_rows' => array_keys($errors),
            ]);

            throw ValidationException::withMessages($errors);
        }

        /*
         * Pastikan worksheet memiliki setidaknya
         * satu baris data yang valid.
         */
        if (empty($validRows)) {
            Log::warning('ScheduleImport: tidak ada data valid');

            throw ValidationException::withMessages([
                'file' => [
                    'Worksheet pertama tidak memiliki data jadwal yang dapat diimport.',
                ],
            ]);
        }

        /*
         * 10. Simpan seluruh jadwal dalam satu transaksi.
         */
        DB::transaction(function () use ($validRows): void {
            foreach ($validRows as $item) {
                JadwalPelajaran::query()->create(
                    $item['data']
                );
            }
        });

        Log::info('ScheduleImport: import berhasil', [
            'inserted_rows' => count($validRows),
        ]);
    }

    /**
     * Normalisasi header dan nilai setiap kolom Excel.
     */
    private function normalizeRow(array $row): array
    {
        $normalizedRow = [];

        foreach ($row as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));

            $normalizedKey = preg_replace(
                '/[^a-z0-9]+/',
                '_',
                $normalizedKey
            );

            $normalizedKey = trim($normalizedKey, '_');

            $normalizedRow[$normalizedKey] = $value;
        }

        $fields = [
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

        $data = [];

        foreach ($fields as $field) {
            $value = $normalizedRow[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if (in_array($field, ['semester', 'hari'], true)) {
                $value = is_string($value)
                    ? strtoupper($value)
                    : $value;
            }

            if ($field === 'tingkat' && is_numeric($value)) {
                $value = (int) $value;
            }

            if (in_array($field, ['jam_mulai', 'jam_selesai'], true)) {
                $value = $this->normalizeTime($value);
            }

            $data[$field] = $value;
        }

        return $data;
    }

    /**
     * Normalisasi waktu Excel menjadi format H:i.
     */
    private function normalizeTime(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Excel dapat menyimpan waktu sebagai angka desimal.
        if (
            is_numeric($value) &&
            (float) $value >= 0 &&
            (float) $value < 1
        ) {
            return ExcelDate::excelToDateTimeObject(
                (float) $value
            )->format('H:i');
        }

        // Normalisasi format H:i:s menjadi H:i.
        if (is_string($value)) {
            $value = trim($value);

            if (preg_match(
                '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',
                $value
            )) {
                return substr($value, 0, 5);
            }
        }

        return $value;
    }

    /**
     * Periksa apakah seluruh kolom pada baris kosong.
     */
    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Periksa bentrok dengan jadwal yang sudah tersimpan.
     *
     * Bentrok jika:
     * - Tahun akademik sama.
     * - Hari sama.
     * - Waktu bertumpang tindih.
     * - Kelas sama atau guru sama.
     */
    private function hasDatabaseConflict(array $data): bool
    {
        return JadwalPelajaran::query()
            ->where(
                'tahun_akademik_id',
                $data['tahun_akademik_id']
            )
            ->whereRaw('UPPER(hari) = ?', [$data['hari']])
            ->where('jam_mulai', '<', $data['jam_selesai'])
            ->where('jam_selesai', '>', $data['jam_mulai'])
            ->where(function ($query) use ($data) {
                $query
                    ->where('kelas_id', $data['kelas_id'])
                    ->orWhere('guru_id', $data['guru_id']);
            })
            ->exists();
    }

    /**
     * Periksa bentrok antarjadwal dalam file Excel.
     */
    private function hasFileConflict(
        array $first,
        array $second
    ): bool {
        $sameYear =
            (int) $first['tahun_akademik_id'] ===
            (int) $second['tahun_akademik_id'];

        $sameDay =
            $first['hari'] === $second['hari'];

        $sameClass =
            (int) $first['kelas_id'] ===
            (int) $second['kelas_id'];

        $sameTeacher =
            (int) $first['guru_id'] ===
            (int) $second['guru_id'];

        $overlap =
            $first['jam_mulai'] < $second['jam_selesai'] &&
            $first['jam_selesai'] > $second['jam_mulai'];

        return $sameYear
            && $sameDay
            && ($sameClass || $sameTeacher)
            && $overlap;
    }
}
