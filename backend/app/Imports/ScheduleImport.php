<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ScheduleImport implements Import, WithMultipleSheets
{
    public readonly ScheduleSheetImport $sheet;
    public function __construct(int $period) { $this->sheet = new ScheduleSheetImport($period); }

    /**
     * Hanya memproses worksheet pertama (Sheet1).
     */
    public function sheets(): array
    {
        return [
            0 => $this->sheet,
        ];
    }
}
