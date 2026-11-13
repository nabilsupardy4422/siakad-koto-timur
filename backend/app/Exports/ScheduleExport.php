<?php

namespace App\Exports;

use App\Models\JadwalPelajaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ScheduleExport extends DefaultValueBinder implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithColumnFormatting,
    WithCustomValueBinder
{
    public function __construct(
        private readonly array $filters = []
    ) {}

    /**
     * Query data jadwal beserta relasinya.
     */
    public function query(): Builder|QueryBuilder|Relation
    {
        return JadwalPelajaran::query()
            ->with([
                'tahunAkademik',
                'kelas',
                'mapel',
                'guru',
            ])
            ->when(
                $this->filters['academic_year_id'] ?? null,
                fn (Builder $query, $value) =>
                    $query->where('tahun_akademik_id', $value)
            )
            ->when(
                $this->filters['class_id'] ?? null,
                fn (Builder $query, $value) =>
                    $query->where('kelas_id', $value)
            )
            ->when(
                $this->filters['subject_id'] ?? null,
                fn (Builder $query, $value) =>
                    $query->where('mapel_id', $value)
            )
            ->when(
                $this->filters['teacher_id'] ?? null,
                fn (Builder $query, $value) =>
                    $query->where('guru_id', $value)
            )
            ->when(
                $this->filters['day'] ?? null,
                fn (Builder $query, $value) =>
                    $query->whereRaw(
                        'UPPER(hari) = ?',
                        [strtoupper($value)]
                    )
            )
            ->when(
                $this->filters['semester'] ?? null,
                fn (Builder $query, $value) =>
                    $query->whereHas(
                        'tahunAkademik',
                        fn (Builder $yearQuery) =>
                            $yearQuery->where(
                                'semester',
                                strtoupper($value)
                            )
                    )
            )
            ->orderBy('tahun_akademik_id')
            ->orderBy('kelas_id')
            ->orderBy('hari')
            ->orderBy('jam_mulai');
    }

    /**
     * Header kolom Excel.
     */
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
     * Mapping data database menjadi data yang mudah dibaca TU.
     */
    public function map($schedule): array
    {
        return [
            $schedule->tahunAkademik->tahun_mulai . '/' .
                $schedule->tahunAkademik->tahun_selesai,

            $schedule->tahunAkademik->semester,

            $schedule->kelas->tingkat,

            $schedule->kelas->nama,

            $schedule->mapel->kode,

            (string) $schedule->guru->nip,

            strtoupper($schedule->hari),

            substr((string) $schedule->jam_mulai, 0, 5),

            substr((string) $schedule->jam_selesai, 0, 5),
        ];
    }

    /**
     * Format kolom NIP sebagai teks.
     */
    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_TEXT,
        ];
    }

    /**
     * Paksa nilai kolom F menjadi string saat ditulis ke Excel.
     */
    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === 'F' && $value !== null) {
            $cell->setValueExplicit(
                (string) $value,
                DataType::TYPE_STRING
            );

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
