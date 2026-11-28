<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TuAcademicReadService as Read;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PrincipalGradeMonitoringController extends Controller
{
    public function __invoke(Request $request)
    {
        $c=Read::principalContext($request,false);extract($c);
        if($d['view']==='catalog') {
            $components=Read::bounded(DB::table('komponen_nilai')->whereIn('jadwal_pelajaran_id',$schedules->pluck('id'))->select('id','jadwal_pelajaran_id','nama','bobot')->orderBy('id'),30000);
            return response()->json(['data'=>['classes'=>$classes->values(),'schedules'=>$schedules->values(),'components'=>$components], 'meta'=>['academic_year_id'=>(int)$year->id,'semester'=>$year->semester,'active'=>$active]])->header('Cache-Control','private, no-store');
        }
        abort_unless(isset($d['class_id']),422,'Pilih rombel terlebih dahulu.');
        $c['principal']=true;
        $projection=\App\Services\TuGradeDataset::build($c);
        return Read::result($c,$projection['summary'],$projection['rows'],$projection['warnings']);
    }
}
