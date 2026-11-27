<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\ScheduleContinuationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
final class ScheduleContinuationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data=$request->validate([
            'mode'=>'required|in:preview,commit',
            'source_period_id'=>'required|integer|exists:tahun_akademik,id',
            'target_period_id'=>'required|integer|different:source_period_id|exists:tahun_akademik,id',
            'mappings'=>'required|array|min:1|max:100',
            'mappings.*'=>'required|array:source_class_id,target_class_id',
            'mappings.*.source_class_id'=>'required|integer|distinct|exists:kelas,id',
            'mappings.*.target_class_id'=>'required|integer|distinct|exists:kelas,id',
            'token'=>'required_if:mode,commit|nullable|string|max:100',
        ]);
        $result=ScheduleContinuationService::run((int)$request->user()->id,$data);
        return ApiResponse::success($result);
    }
}
