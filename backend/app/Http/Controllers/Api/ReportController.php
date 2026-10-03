<?php

namespace App\Http\Controllers\Api;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Services\Reporting\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService
    ) {
    }

    public function index(
        ReportRequest $request,
        string $reportType
    ): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse {
        $user = $request->user();
        $filters = $request->filters();

        $report = $this->reportService->build(
            $reportType,
            $user,
            $filters
        );

        $filename = 'laporan-' . $reportType . '-' . now()->format('Ymd-His');

        if ($filters['format'] === 'excel') {
            return Excel::download(
                new ReportExport(
                    $report['headings'],
                    $report['rows']
                ),
                $filename . '.xlsx'
            );
        }

        $pdf = Pdf::loadView(
            'reports.pdf',
            [
                'title' => $report['title'],
                'headings' => $report['headings'],
                'rows' => $report['rows'],
                'generatedAt' => now(),
                'generatedBy' => $user->name,
                'filters' => $filters,
            ]
        );

        $pdf->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }
}