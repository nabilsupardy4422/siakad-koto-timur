<?php

namespace App\Http\Controllers\Api;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Services\Reporting\ReportDocument;
use App\Services\Reporting\ReportWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Reporting\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    public function index(ReportRequest $request, string $reportType): Response|BinaryFileResponse
    {
        $user = $request->user();
        $filters = $request->filters();
        $expected = $filters['preview_hash'] ?? null;
        unset($filters['preview_hash']);
        $report = $this->reportService->build($reportType, $user, $filters);
        $meta = ReportDocument::metadata($reportType, $user, $report);
        if ($expected !== null) {
            abort_unless($user->role?->code === 'GURU', 403);
            abort_unless(hash_equals(ReportWorkspace::fingerprint($reportType, $report, $meta), $expected), 409, 'Data laporan berubah. Tampilkan pratinjau kembali.');
        }
        $filename = 'laporan-'.$reportType.'-'.now()->format('Ymd-His');

        if ($filters['format'] === 'excel') {
            return Excel::download(new ReportExport($report['headings'], $report['rows'], $meta), $filename.'.xlsx');
        }

        $pdf = Pdf::loadView('reports.pdf', [
            'meta' => $meta,
            'sections' => ReportDocument::sections($reportType, $report),
            'logo' => ReportDocument::logo(),
        ])->setPaper('a4', 'landscape');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text($canvas->get_width() - 132, $canvas->get_height() - 27,
            'Halaman {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.30, 0.40, 0.49]);
        return $pdf->download($filename.'.pdf');
    }
    public function context(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role?->code === 'GURU', 403);
        return response()->json(['data' => ReportWorkspace::context($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function preview(ReportRequest $request, string $reportType): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->role?->code === 'GURU', 403);
        $filters = $request->filters();
        $page = (int) ($filters['page'] ?? 1);
        unset($filters['page']);
        $report = $this->reportService->build($reportType, $user, $filters);
        $meta = ReportDocument::metadata($reportType, $user, $report);
        $size = 8;
        $total = $report['rows']->count();
        $last = max(1, (int) ceil($total / $size));
        abort_if($page > $last, 422, 'Halaman pratinjau tidak tersedia.');
        return response()->json(['data' => [
            'metadata' => $meta,
            'headings' => $report['headings'],
            'rows' => $report['rows']->forPage($page, $size)->values()->all(),
            'total' => $total, 'page' => $page, 'last_page' => $last, 'per_page' => $size,
            'preview_hash' => ReportWorkspace::fingerprint($reportType, $report, $meta),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
