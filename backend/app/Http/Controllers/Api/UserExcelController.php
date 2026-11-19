<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class UserExcelController extends Controller
{
    /**
     * Download template resmi Import Pengguna SIAKAD.
     *
     * Maksimal 1000 baris data:
     * Header = baris 1
     * Data   = baris 2 sampai 1001
     *
     * Tidak mengubah database.
     */
    public function template()
    {
        $template = new class implements
            FromArray,
            WithHeadings,
            WithEvents
        {
            public function array(): array
            {
                return [];
            }

            public function headings(): array
            {
                return [
                    'username',
                    'name',
                    'email',
                    'role_code',
                ];
            }

            public function registerEvents(): array
            {
                return [
                    AfterSheet::class => function (
                        AfterSheet $event
                    ): void {
                        $sheet = $event->sheet->getDelegate();

                        // Satu worksheet utama.
                        $sheet->setTitle('Import Pengguna');

                        // Lebar kolom.
                        $sheet->getColumnDimension('A')
                            ->setWidth(26);

                        $sheet->getColumnDimension('B')
                            ->setWidth(34);

                        $sheet->getColumnDimension('C')
                            ->setWidth(38);

                        $sheet->getColumnDimension('D')
                            ->setWidth(24);

                        // Desain header.
                        $sheet->getStyle('A1:D1')
                            ->applyFromArray([
                                'font' => [
                                    'bold' => true,
                                    'size' => 11,
                                    'color' => [
                                        'rgb' => 'FFFFFF',
                                    ],
                                ],

                                'fill' => [
                                    'fillType' => Fill::FILL_SOLID,
                                    'startColor' => [
                                        'rgb' => '123F64',
                                    ],
                                ],

                                'alignment' => [
                                    'vertical' =>
                                        Alignment::VERTICAL_CENTER,
                                ],
                            ]);

                        $sheet->getRowDimension(1)
                            ->setRowHeight(28);

                        /*
                         * Username menggunakan format Text.
                         *
                         * Baris 2-1001 = 1000 data pengguna.
                         * Disesuaikan dengan MAX_ROWS
                         * pada UserImportPreviewController.
                         */
                        $sheet->getStyle('A2:A1001')
                            ->getNumberFormat()
                            ->setFormatCode(
                                NumberFormat::FORMAT_TEXT
                            );

                        // Header tetap terlihat saat scroll.
                        $sheet->freezePane('A2');

                        // Filter pada header.
                        $sheet->setAutoFilter('A1:D1');
                    },
                ];
            }
        };

        return Excel::download(
            $template,
            'template-import-pengguna.xlsx'
        );
    }
}
