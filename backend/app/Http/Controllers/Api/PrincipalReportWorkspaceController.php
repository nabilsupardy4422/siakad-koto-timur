<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\{ReportAccess,ReportService,ReportDocument,ReportWorkspace};
use App\Exports\ReportExport;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

final class PrincipalReportWorkspaceController extends Controller
{
    private const FILTERS = [
        'students'=>['academic_year_id','class_id','search'], 'teachers'=>['search'], 'classes'=>['academic_year_id'],
        'schedules'=>['academic_year_id','class_id','subject_id','teacher_id'], 'attendance'=>['academic_year_id','class_id','subject_id','date_from','date_to'],
        'grades'=>['academic_year_id','class_id','subject_id'], 'semester-recap'=>['academic_year_id','class_id'],
    ];
    private function dataset(Request $r,string $reportType,bool $export): array
    {
        abort_unless($r->user()?->role?->code==='KEPALA_SEKOLAH',403);
        abort_unless(isset(self::FILTERS[$reportType])&&ReportAccess::allowsType($r->user(),$reportType),422,'Jenis laporan tidak didukung.');
        $filters=self::FILTERS[$reportType];$rules=[];
        foreach($filters as $key)$rules[$key]=match($key){'academic_year_id'=>'required|integer|min:1|exists:tahun_akademik,id','class_id'=>'nullable|integer|min:1|exists:kelas,id','subject_id'=>'nullable|integer|min:1|exists:mapel,id','teacher_id'=>'nullable|integer|min:1|exists:guru,id','search'=>'nullable|string|max:100','date_from'=>'nullable|date_format:Y-m-d','date_to'=>'nullable|date_format:Y-m-d'};
        $rules+=$export?['format'=>'required|in:pdf,excel','preview_hash'=>'required|regex:/^[a-f0-9]{64}$/','confirm_empty'=>'nullable|boolean']:['page'=>'nullable|integer|min:1|max:100000'];
        abort_if(count(array_diff(array_keys($r->query()),array_keys($rules)))>0,422,'Filter tidak didukung.');
        $d=$r->validate($rules);$f=array_filter(array_intersect_key($d,array_flip($filters)),fn($v)=>$v!==null&&$v!=='');
        abort_if(isset($f['date_from'],$f['date_to'])&&$f['date_from']>$f['date_to'],422,'Rentang tanggal terbalik.');
        if($reportType!=='teachers'){
            $c=\App\Services\TuAcademicReadService::principalContextFor($r->user(),array_intersect_key($f,array_flip(['academic_year_id','class_id']))+['view'=>'catalog'],false);
            foreach(['date_from','date_to'] as $key)if(isset($f[$key]))abort_if((int)substr($f[$key],0,4)<(int)$c['year']->tahun_mulai||(int)substr($f[$key],0,4)>(int)$c['year']->tahun_selesai,422,'Tanggal di luar tahun kalender periode.');
        }
        $report=app(ReportService::class)->build($reportType,$r->user(),$f,30000,true);
        if($reportType==='attendance')$report['note']='Satu catatan siswa per pertemuan. Presensi valid sampai hari ini WIB; tanggal masa depan dikecualikan. Bukan bukti KBM.';
        if($reportType==='semester-recap')$report['note']='H/I/S/A = Hadir/Izin/Sakit/Alpa. Persentase hadir dari catatan valid sampai hari ini WIB; tanggal masa depan dikecualikan. Rata-rata komponen tanpa bobot, bukan rapor. Kosong berarti belum ada data.';
        $meta=ReportDocument::metadata($reportType,$r->user(),$report);$hash=ReportWorkspace::fingerprint($reportType,$report,$meta);
        return [$d,$report,$meta,$hash];
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
