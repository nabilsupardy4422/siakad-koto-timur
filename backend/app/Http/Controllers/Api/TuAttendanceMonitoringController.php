<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TuAcademicReadService as Read;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class TuAttendanceMonitoringController extends Controller
{
    public function __invoke(Request $request)
    {
        $c=Read::context($request,true);extract($c);
        if($d['view']==='catalog') return Read::catalog($c);
        $projection=\App\Services\TuAttendanceDataset::build($c);
        return Read::result($c,$projection['summary'],$projection['rows'],$projection['warnings']);
    }
}
