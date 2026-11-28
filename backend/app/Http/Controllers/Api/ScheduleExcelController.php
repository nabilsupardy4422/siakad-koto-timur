<?php

namespace App\Http\Controllers\Api;

use App\Exports\ScheduleExport;
use App\Imports\ScheduleImport;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\TahunAkademik;
use App\Models\JadwalPelajaran;
use App\Services\ScheduleWriteService;
use App\Services\ScheduleWorkbookService;
use App\Support\HomeroomContinuationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ScheduleExcelController extends Controller
{
    /**
     * Download template Excel jadwal pelajaran.
     */
    public function template(Request $request)
    {
        $params=$request->validate(['academic_year_id'=>'nullable|integer|exists:tahun_akademik,id']);
        if (!empty($params['academic_year_id'])) {
            $path=ScheduleWorkbookService::template(TahunAkademik::findOrFail($params['academic_year_id']));
            return response()->download($path,'template-jadwal-pelajaran.xlsx')->deleteFileAfterSend(true);
        }
        $template = new class implements FromArray, WithHeadings, WithColumnFormatting {
            public function array(): array
            {
                return [];
            }

            public function headings(): array
            {
                return [
                    'tahun_akademik',
                    'semester',
                    'tingkat',
                    'kelas',
                    'kode_mapel',
                    'nip_guru',
                    'hari',
                    'jam_mulai',
                    'jam_selesai',
                ];
            }

            /**
             * Kolom F (NIP Guru) menggunakan format teks.
             */
            public function columnFormats(): array
            {
                return [
                    'F' => NumberFormat::FORMAT_TEXT,
                ];
            }
        };

        return Excel::download(
            $template,
            'template-jadwal-pelajaran.xlsx'
        );
    }

    /**
     * Import jadwal pelajaran dari file Excel.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id'=>'required|integer|exists:tahun_akademik,id',
            'mode'=>'required|in:preview,commit',
            'token'=>'required_if:mode,commit|nullable|string|max:100',
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
            ],
        ]);

        try {
            $action = function () use ($request, $validated) {
                $period=(int)$validated['academic_year_id'];
                $import = new ScheduleImport($period);
                Excel::import($import, $validated['file']);
                $rows=$import->sheet->rows;
                $state=['schedule-import',(int)$request->user()->id,$period,hash_file('sha256',$validated['file']->getRealPath()),$rows];
                $key=(string)config('app.key'); $now=time();
                if ($validated['mode']==='preview') return ApiResponse::success(['rows'=>$rows,'token'=>HomeroomContinuationToken::issue($state,$now,$key),'count'=>count($rows)]);
                abort_unless(HomeroomContinuationToken::verify($validated['token'],$state,$now,$key),409,'Preview berubah atau kedaluwarsa. Lakukan preview ulang.');
                foreach ($rows as $row) {
                    try { ScheduleWriteService::validate($row['data']); }
                    catch (ValidationException $e) { throw ValidationException::withMessages(['baris_'.$row['row_number']=>array_merge(...array_values($e->errors()))]); }
                    JadwalPelajaran::create($row['data']);
                }
                return ApiResponse::message('Data jadwal pelajaran berhasil diimport.', ['status'=>'success','count'=>count($rows)]);
            };
            return $validated['mode']==='preview' ? $action() : ScheduleWriteService::transaction($action);
        } catch (ValidationException $exception) {
            return ApiResponse::message(
                'Import jadwal gagal. Periksa kembali data Excel.',
                [
                    'errors' => $exception->errors(),
                ],
                422
            );
        }
    }

    /**
     * Export jadwal pelajaran ke Excel.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'format'=>'nullable|in:data,teacher,class,batch',
            'include_inactive'=>'nullable|boolean',
            'academic_year_id' => [
                'nullable',
                'integer',
                'exists:tahun_akademik,id',
            ],
            'semester' => [
                'nullable',
                'string',
                'max:20',
            ],
            'class_id' => [
                'nullable',
                'integer',
                'exists:kelas,id',
            ],
            'subject_id' => [
                'nullable',
                'integer',
                'exists:mapel,id',
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                'exists:guru,id',
            ],
            'day' => [
                'nullable',
                'string',
                'max:20',
            ],
        ]);

        $kind=$validated['format'] ?? 'data';
        if ($kind!=='data') {
            $periodId=$validated['academic_year_id'] ?? null;
            abort_unless($periodId,422,'Pilih periode akademik.');
            $id=$kind==='teacher'?($validated['teacher_id']??null):($validated['class_id']??null);
            abort_if($kind!=='batch' && !$id,422,'Pilih guru atau rombel.');
            [$path,$name]=ScheduleWorkbookService::distribution(TahunAkademik::findOrFail($periodId),$kind,$id,$request->boolean('include_inactive'));
            return response()->download($path,$name)->deleteFileAfterSend(true);
        }
        $filters = array_filter(
            $validated,
            fn ($value) => $value !== null && $value !== ''
        );

        return Excel::download(
            new ScheduleExport($filters),
            'jadwal-pelajaran.xlsx'
        );
    }
}
