<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ScheduleImport implements Import, WithMultipleSheets
{
    /**
     * Hanya memproses worksheet pertama (Sheet1).
     */
    public function sheets(): array
    {
        return [
            0 => new ScheduleSheetImport(),
        ];
    }
}
