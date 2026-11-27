<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ReportExport extends DefaultValueBinder implements FromArray, WithHeadings, WithCustomValueBinder, WithStrictNullComparison, WithCustomStartCell, WithTitle, WithEvents
{
    public const HEADER_ROW = 9;
    public const DATA_ROW = 10;

    public function __construct(
        private readonly array $headings,
        private readonly Collection $rows,
        private readonly array $meta,
    ) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        // Retain literal text (identifiers and untrusted user text); never interpret it as a formula.
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }
        return parent::bindValue($cell, $value);
    }

    public function array(): array
    {
        return $this->rows->map(fn (array $row): array => array_values($row))->values()->all();
    }

    public function headings(): array { return $this->headings; }
    public function title(): string { return $this->meta['sheet']; }
    public function startCell(): string { return 'A'.self::HEADER_ROW; }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $lastColumn = Coordinate::stringFromColumnIndex(count($this->headings));
            $end = self::HEADER_ROW + $this->rows->count();
            $table = 'A'.self::HEADER_ROW.':'.$lastColumn.$end;
            $sheet->setShowGridlines(false);
            $sheet->getStyle('A1:'.$lastColumn.$end)->getFont()->setName('Calibri')->setSize(11)->getColor()->setRGB('17344E');
            $sheet->getStyle('A1:'.$lastColumn.$end)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $widths = [];
            foreach ($this->headings as $i => $heading) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $width = match ($heading) {
                    'ID' => 9, 'NIS','NISN','NIPD' => 17, 'NIP' => 23,
                    'Nama Siswa','Siswa','Nama Guru','Guru','Mata Pelajaran','Email' => 30,
                    'Catatan' => 38, 'Komponen','Username','Status Keanggotaan' => 23,
                    'Tahun Akademik','Tanggal Mulai','Tanggal Selesai','Tanggal' => 18,
                    'Hadir','Izin','Sakit','Alpa','Bobot','Tingkat','Jam Mulai','Jam Selesai' => 11,
                    'Rata-rata Komponen (Tanpa Bobot)','Hadir dari Catatan (%)','Total Catatan Presensi','Jumlah Nilai Tercatat' => 22,
                    default => 19,
                };
                $widths[$i] = $width;
                $sheet->getColumnDimension($col)->setWidth($width);
                if ($end >= self::DATA_ROW) {
                    $range = $col.self::DATA_ROW.':'.$col.$end;
                    if (in_array($heading, ['NIS','NISN','NIP','NIPD'], true)) $sheet->getStyle($range)->getNumberFormat()->setFormatCode('@');
                    if (in_array($heading, ['Bobot','Total Bobot Jadwal','Nilai Tercatat','Rata-rata Komponen (Tanpa Bobot)','Hadir dari Catatan (%)'], true)) {
                        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('0.00');
                    }
                }
            }
            $metaRows = [
                1 => mb_strtoupper($this->meta['school']),
                2 => $this->meta['title'],
                3 => 'Konteks: '.$this->meta['scope'],
                4 => 'Periode: '.$this->meta['period'],
                5 => 'Filter: '.$this->meta['filters'],
                6 => 'Dibuat: '.$this->meta['generated'].' | Jumlah baris: '.$this->meta['count'],
                7 => $this->meta['note'].($this->rows->isEmpty() ? ' Tidak ada data sesuai filter laporan.' : ''),
            ];
            foreach ($metaRows as $row => $label) {
                $range = 'A'.$row.':'.$lastColumn.$row;
                $sheet->mergeCells($range);
                // Explicit type here too: metadata is not trusted formula input.
                $sheet->setCellValueExplicit('A'.$row, $label, DataType::TYPE_STRING);
                $style = $sheet->getStyle($range);
                $style->getAlignment()->setWrapText(true)->setIndent(1);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($row <= 2 ? '17344E' : 'EDF3F8');
                $style->getFont()->getColor()->setRGB($row <= 2 ? 'FFFFFF' : '17344E');
                if ($row <= 2) $style->getFont()->setBold(true)->setSize($row === 1 ? 11 : 15);
                $lines = max(1, (int) ceil(mb_strlen($label) / max(15, (int) (array_sum($widths) * .85))));
                $sheet->getRowDimension($row)->setRowHeight(max($row === 2 ? 32 : 22, $lines * 17 + 8));
            }
            $sheet->getRowDimension(8)->setRowHeight(9);
            $head = $sheet->getStyle('A'.self::HEADER_ROW.':'.$lastColumn.self::HEADER_ROW);
            $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('285F98');
            $head->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(38);
            if ($end >= self::DATA_ROW) {
                $dataRange = 'A'.self::DATA_ROW.':'.$lastColumn.$end;
                $sheet->getStyle($dataRange)->getAlignment()->setWrapText(true);
                foreach ($this->rows->values() as $index => $rowValues) {
                    $r = self::DATA_ROW + $index;
                    if ($index % 2 === 1) {
                        $sheet->getStyle('A'.$r.':'.$lastColumn.$r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDF3F8');
                    }
                    $lines = 1;
                    foreach (array_values($rowValues) as $i => $value) {
                        if ($value === null) continue;
                        $lineCount = 0;
                        foreach (explode("\n", (string) $value) as $line) {
                            $lineCount += max(1, (int) ceil(mb_strlen($line) / max(8, (int) ($widths[$i] * .85))));
                        }
                        $lines = max($lines, $lineCount);
                    }
                    $sheet->getRowDimension($r)->setRowHeight(min(409, max(21, $lines * 15 + 6)));
                }
            }
            $sheet->setAutoFilter($table);
            $sheet->freezePane('A'.self::DATA_ROW);
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, self::HEADER_ROW)->setPrintArea('A1:'.$lastColumn.$end);
            $sheet->getHeaderFooter()->setOddFooter('&LSIAKAD | Dokumen informasi akademik&RHalaman &P / &N');
        }];
    }
}
