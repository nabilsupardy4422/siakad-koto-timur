<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\{TuReportDataset,ReportAccess,ReportService,ReportDocument,ReportWorkspace};
use App\Exports\ReportExport;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

final class TuReportController extends Controller
{
    private function dataset(Request $r,string $reportType,bool $export): array
    {
        abort_unless($r->user()?->role?->code==='TU',403);
        abort_unless(isset(TuReportDataset::FILTERS[$reportType]),422,'Jenis laporan tidak didukung.');
        abort_unless(ReportAccess::allowsType($r->user(),$reportType),403);
        $filters=TuReportDataset::FILTERS[$reportType];
        $rules=[];foreach($filters as $key)$rules[$key]=match($key){
            'academic_year_id'=>'required|integer|min:1|exists:tahun_akademik,id',
            'class_id'=>(str_starts_with($reportType,'grade-')?'required':'nullable').'|integer|min:1|exists:kelas,id',
            'subject_id'=>'nullable|integer|min:1|exists:mapel,id','teacher_id'=>'nullable|integer|min:1|exists:guru,id','schedule_id'=>'nullable|integer|min:1|exists:jadwal_pelajaran,id','student_id'=>'nullable|integer|min:1|exists:siswa,id',
            'level'=>'nullable|in:10,11,12','date'=>'required|date_format:Y-m-d','month'=>'required|date_format:Y-m', 'date_from'=>'nullable|date_format:Y-m-d','date_to'=>'nullable|date_format:Y-m-d', 'search'=>'nullable|string|max:100','status'=>'nullable|in:active,inactive',};
        $rules+=$export?['format'=>'required|in:pdf,excel','preview_hash'=>'required|regex:/^[a-f0-9]{64}$/','confirm_empty'=>'nullable|boolean']:['page'=>'nullable|integer|min:1|max:100000'];
        abort_if(count(array_diff(array_keys($r->query()),array_keys($rules)))>0,422,'Filter tidak didukung untuk jenis laporan ini.');
        $d=$r->validate($rules);$f=array_filter(array_intersect_key($d,array_flip($filters)),fn($v)=>$v!==null&&$v!=='');
        abort_if(isset($f['date_from'],$f['date_to'])&&$f['date_from']>$f['date_to'],422,'Rentang tanggal terbalik.');
        $report=TuReportDataset::build($reportType,$r->user(),$f,app(ReportService::class));$meta=ReportDocument::metadata($reportType,$r->user(),$report);$hash=ReportWorkspace::fingerprint($reportType,$report,$meta);
        return [$d,$report,$meta,$hash];
    }
    public function studentOptions(Request $r)
    {
        abort_unless($r->user()?->role?->code==='TU',403);
        $rules=['academic_year_id'=>'required|integer|min:1|exists:tahun_akademik,id','class_id'=>'nullable|integer|min:1|exists:kelas,id','subject_id'=>'nullable|integer|min:1|exists:mapel,id','schedule_id'=>'nullable|integer|min:1|exists:jadwal_pelajaran,id'];
        abort_if(count(array_diff(array_keys($r->query()),array_keys($rules)))>0,422,'Filter tidak didukung.');
        $f=array_filter($r->validate($rules),fn($v)=>$v!==null&&$v!=='');
        $c=\App\Services\TuAcademicReadService::contextFor($r->user(),$f+['view'=>'catalog'],false);
        $ids=isset($f['subject_id'])||isset($f['schedule_id'])?$c['scoped']->pluck('kelas_id'):$c['selected']->pluck('id');
        $rows=\App\Services\TuAcademicReadService::members($ids)->filter(fn($m)=>\App\Services\TuAcademicReadService::validInterval($m)&&$m->tanggal_mulai<=$c['date'])->unique('siswa_id')->sortBy([['nama_lengkap','asc'],['siswa_id','asc']])->map(fn($m)=>\App\Services\TuAcademicReadService::student($m))->values();
        return response()->json(['data'=>$rows,'filters'=>$f])->header('Cache-Control','private, no-store');
    }
    public function preview(Request $r,string $reportType)
    {
        [$d,$report,$meta,$hash]=$this->dataset($r,$reportType,false);$size=8;$total=$report['rows']->count();$page=(int)($d['page']??1);$last=max(1,(int)ceil($total/$size));
        abort_if($page>$last,422,'Halaman tidak tersedia.');
        return response()->json(['data'=>['metadata'=>$meta,'headings'=>$report['headings'],'rows'=>$report['rows']->forPage($page,$size)->values()->all(),'total'=>$total,'page'=>$page,'last_page'=>$last,'per_page'=>$size,'preview_hash'=>$hash,'effective_filters'=>$report['effective_filters'],'summary'=>$report['summary']??[],'warnings'=>$report['warnings']??[]]])->header('Cache-Control','private, no-store');
    }
    public function export(Request $r,string $reportType)
    {
        [$d,$report,$meta,$hash]=$this->dataset($r,$reportType,true);
        abort_unless(hash_equals($hash,$d['preview_hash']),409,'Data laporan berubah. Tampilkan pratinjau kembali.');
        abort_if($report['rows']->isEmpty()&&!$r->boolean('confirm_empty'),422,'Konfirmasi dokumen tanpa data diperlukan.');
        $name='laporan-'.$reportType.'-'.now()->format('Ymd-His');
        if($d['format']==='excel'){ $response=Excel::download(new ReportExport($report['headings'],$report['rows'],$meta),$name.'.xlsx'); $response->headers->set('Cache-Control','private, no-store');return $response; }
        abort_if($report['rows']->count()>3000,422,'PDF maksimal 3.000 baris. Persempit filter atau gunakan Excel.');
        $pdf=Pdf::loadView('reports.pdf',['meta'=>$meta,'sections'=>ReportDocument::sections($reportType,$report),'logo'=>ReportDocument::logo()])->setPaper('a4','landscape');
        $pdf->render();$dom=$pdf->getDomPDF();$canvas=$dom->getCanvas();$canvas->page_text($canvas->get_width()-132,$canvas->get_height()-27,'Halaman {PAGE_NUM} / {PAGE_COUNT}',$dom->getFontMetrics()->getFont('DejaVu Sans','normal'),8,[.30,.40,.49]);
        return $pdf->download($name.'.pdf')->header('Cache-Control','private, no-store');
    }
}
