<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportCardRequest;
use App\Http\Resources\ReportCardResource;
use App\Models\Rapor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use App\Services\ReportCardDistribution;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportCardController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Rapor::class);

        $user = $request->user();

        $query = Rapor::query()
            ->with([
                'siswa',
                'tahunAkademik',
                'uploadedBy',
            ]);

        if ($user->role?->code === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return response()->json([
                    'message' => 'Data siswa tidak ditemukan.',
                ], 403);
            }

            $query->where('siswa_id', $studentId)->where('distribution_status','active');
        }

        if ($request->filled('academic_year_id')) {
            $query->where(
                'tahun_akademik_id',
                $request->integer('academic_year_id')
            );
        }

        if ($request->filled('student_id')) {
            $query->where(
                'siswa_id',
                $request->integer('student_id')
            );
        }

        if ($request->filled('type')) {
            $query->where(
                'jenis_rapor',
                $request->string('type')->toString()
            );
        }

        if ($request->filled('semester')) {
            $semester = $request->string('semester')->toString();

            $query->whereHas(
                'tahunAkademik',
                function ($academicYearQuery) use ($semester) {
                    $academicYearQuery->where(
                        'semester',
                        $semester
                    );
                }
            );
        }

        $perPage = $request->integer('per_page', 15);

        $reportCards = $query
            ->latest('id')
            ->paginate($perPage);

        return ReportCardResource::collection($reportCards)->response()->header('Cache-Control','private, no-store');
    }

    public function store(
        StoreReportCardRequest $request
    ) {
        Gate::authorize('create', Rapor::class);

        $reportCard = ReportCardDistribution::store($request->validated(), $request->file('file'), (int)$request->user()->id);

        $reportCard->load([
            'siswa',
            'tahunAkademik',
            'uploadedBy',
        ]);

        return (new ReportCardResource($reportCard))
            ->response()
            ->setStatusCode(201)->header('Cache-Control','private, no-store');
    }

    public function show(
        Rapor $reportCard
    ) {
        Gate::authorize(
            'view',
            $reportCard
        );

        $reportCard->load([
            'siswa',
            'tahunAkademik',
            'uploadedBy',
        ]);

        return (new ReportCardResource($reportCard))->response()->header('Cache-Control','private, no-store');
    }

    public function download(
        Rapor $reportCard
    ): BinaryFileResponse {
        Gate::authorize(
            'view',
            $reportCard
        );

        $disk = Storage::disk('local');

        if (! ReportCardDistribution::available($reportCard->file_path)) {
            abort(404);
        }

        $absolutePath = $disk->path(
            $reportCard->file_path
        );

        return response()->download(
            $absolutePath,
            $reportCard->file_name,
            [
                'Content-Type' => $reportCard->file_mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function destroy(Request $request, Rapor $reportCard): JsonResponse
    {
        Gate::authorize('delete', $reportCard);
        $d=$request->validate(['version'=>'required|integer|min:1','reason'=>'required|string|min:3|max:1000']);
        ReportCardDistribution::change($reportCard,$d,(int)$request->user()->id,'revoke');
        return response()->json(['message'=>'Distribusi rapor dicabut; arsip tetap tersimpan.']);
    }

    public function revise(Request $request,Rapor $reportCard): JsonResponse
    {
        Gate::authorize('delete',$reportCard);
        $d=$request->validate(['version'=>'required|integer|min:1','reason'=>'required|string|min:3|max:1000','action'=>'required|in:revise,reissue','file'=>'required|file|mimes:pdf|max:10240']);
        $r=ReportCardDistribution::change($reportCard,$d,(int)$request->user()->id,$d['action'],$request->file('file'));
        return (new ReportCardResource($r->load(['siswa','tahunAkademik','uploadedBy'])))->response()->header('Cache-Control','private, no-store');
    }

    public function history(Request $request,Rapor $reportCard): JsonResponse
    {
        Gate::authorize('delete',$reportCard);
        $request->validate(['page'=>'nullable|integer|min:1|max:100000']);
        abort_if(count(array_diff(array_keys($request->query()),['page']))>0,422,'Filter tidak didukung.');
        $events=DB::table('rapor_events as e')->join('users as u','u.id','=','e.actor_id')->where('e.rapor_id',$reportCard->id)->orderByDesc('e.id')->select('e.*','u.name as actor')->paginate(20);
        abort_if($events->currentPage()>$events->lastPage(),422,'Halaman riwayat tidak tersedia.');
        $events->through(function($event){$s=json_decode($event->snapshot,true,512,JSON_THROW_ON_ERROR);unset($event->snapshot);$event->file_name=$s['file_name'];$event->file_size=$s['file_size'];return $event;});
        return response()->json(['data'=>$events->items(),'meta'=>['page'=>$events->currentPage(),'last_page'=>$events->lastPage(),'total'=>$events->total()]])->header('Cache-Control','private, no-store');
    }

    public function archiveDownload(Rapor $reportCard,int $event): BinaryFileResponse
    {
        Gate::authorize('delete',$reportCard);
        $entry=DB::table('rapor_events')->where('rapor_id',$reportCard->id)->where('id',$event)->first();
        abort_unless($entry,404);
        $s=json_decode($entry->snapshot,true,512,JSON_THROW_ON_ERROR);$disk=Storage::disk('local');
        abort_unless(ReportCardDistribution::available($s['file_path']),404);
        return response()->download($disk->path($s['file_path']),$s['file_name'],['Content-Type'=>$s['file_mime_type'],'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
