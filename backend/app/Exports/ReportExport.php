<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ReportExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(
        private readonly array $headings,
        private readonly Collection $rows
    ) {
    }

    public function array(): array
    {
        return $this->rows
            ->map(
                fn (array $row): array => array_values($row)
            )
            ->values()
            ->all();
    }

    public function headings(): array
    {
        return $this->headings;
    }
}