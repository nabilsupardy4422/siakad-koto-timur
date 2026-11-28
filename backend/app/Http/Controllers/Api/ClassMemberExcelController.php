<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Services\ClassMembershipService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ClassMemberExcelController extends Controller
{
    private const HEADERS = ['nisn', 'nipd', 'nama_lengkap'];

    public function template(Request $request, Kelas $class)
    {
        abort_unless($request->user()?->role?->code === 'TU', 403);
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Anggota Kelas');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '173B62']],
        ]);
        foreach (['A'=>22,'B'=>22,'C'=>45] as $col=>$width) $sheet->getColumnDimension($col)->setWidth($width);
        $sheet->getStyle('A2:C101')->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:C101');
        return response()->streamDownload(function () use ($book) {
            try { (new Xlsx($book))->save('php://output'); } finally { $book->disconnectWorksheets(); }
        }, 'template_anggota_kelas.xlsx', ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function input(Request $request, bool $confirm): array
    {
        abort_unless($request->user()?->role?->code === 'TU', 403);
        return $request->validate([
            'file' => ['required','file','mimes:xlsx','max:2048'],
            'tanggal_mulai' => ['required','date_format:Y-m-d'],
            'preview_token' => $confirm ? ['required','string','max:100'] : ['nullable'],
        ]);
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    private function read(string $path): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $info = $reader->listWorksheetInfo($path);
            abort_unless(count($info) === 1 && $info[0]['totalRows'] <= 1001 && $info[0]['totalColumns'] <= 3, 422, 'Gunakan satu sheet, tiga kolom, maksimal 1.000 baris.');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable $e) { abort(422, 'File Excel tidak dapat dibaca.'); }
        try {
            $sheet = $book->getSheet(0);
            $headers = [];
            foreach (['A','B','C'] as $col) $headers[] = strtolower(trim((string)$sheet->getCell($col.'1')->getValue()));
            abort_unless($headers === self::HEADERS, 422, 'Header harus nisn, nipd, nama_lengkap. Unduh template terbaru.');
            $rows = [];
            for ($i=2; $i <= $sheet->getHighestDataRow(); $i++) {
                $values=[]; $bad=false;
                foreach (['A','B','C'] as $col) {
                    $cell=$sheet->getCell($col.$i);
                    $value=$cell->getValue();
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || ($col !== 'C' && $value !== null && $value !== '' && !is_string($value))) $bad=true;
                    $values[]=is_scalar($value) ? trim((string)$value) : '';
                }
                if (!$bad && $values===['','','']) continue;
                $rows[]=['row'=>$i,'nisn'=>$values[0],'nipd'=>$values[1],'nama_excel'=>$values[2],'bad'=>$bad];
            }
            abort_if(!$rows, 422, 'File belum memiliki data siswa.');
            abort_if(count($rows)>ClassMembershipService::LIMIT, 422, 'Maksimal 100 siswa per batch. Bagi file per rombel atau batch.');
            return $rows;
        } finally { $book->disconnectWorksheets(); }
    }

    private function resolve(array $raw, Kelas $class, string $start, ClassMembershipService $service): array
    {
        $nisns = Siswa::whereIn('nisn', array_filter(array_column($raw,'nisn'), fn ($v) => $v !== ''))->get()->keyBy('nisn');
        $nipds = Siswa::whereIn('nipd', array_filter(array_column($raw,'nipd'), fn ($v) => $v !== ''))->get()->keyBy('nipd');
        $rows=[]; $ids=[];
        foreach ($raw as $row) {
            $student = $row['nisn'] !== '' ? $nisns->get($row['nisn']) : $nipds->get($row['nipd']);
            $status = 'Valid'; $note = 'Siap ditempatkan.';
            if ($row['bad']) { $status='Konflik identitas'; $note='Formula dilarang; NISN/NIPD harus disimpan sebagai teks.'; }
            elseif (!$student) { $status='Tidak ditemukan'; $note='Identitas siswa tidak ditemukan.'; }
            elseif (($row['nisn']!=='' && $student->nisn!==$row['nisn']) || ($row['nipd']!=='' && $student->nipd!==$row['nipd']) || $row['nama_excel']==='' || $this->normalize($row['nama_excel'])!==$this->normalize($student->nama_lengkap)) { $status='Konflik identitas'; $note='NISN, NIPD atau nama tidak sesuai Data Master.'; }
            if ($student) $ids[]=(int)$student->id;
            unset($row['bad']);
            $rows[]=array_merge($row,['siswa_id'=>$student?->id,'nama_database'=>$student?->nama_lengkap,'status'=>$status,'keterangan'=>$note]);
        }
        $snapshot=$service->snapshot($class,$ids,$start);
        $byId=collect($snapshot['rows'])->keyBy('siswa_id'); $counts=array_count_values($ids);
        foreach ($rows as &$row) {
            $id=$row['siswa_id'];
            if ($id && ($counts[$id]??0)>1) { $row['status']='Duplikat'; $row['keterangan']='Semua kemunculan siswa duplikat ditolak.'; }
            elseif ($row['status']==='Valid') {
                $current=$byId->get($id);
                // Check against the same identity snapshot that will be authenticated at commit.
                if (!$current || ($row['nisn']!=='' && $row['nisn']!==$current['nisn']) || ($row['nipd']!=='' && $row['nipd']!==$current['nipd']) || $this->normalize($row['nama_excel'])!==$this->normalize($current['nama_lengkap']??'')) { $row['status']='Konflik identitas'; $row['keterangan']='Identitas berubah. Periksa ulang.'; }
                elseif (!$current['valid']) { $row['status']='Di kelas lain'; $row['keterangan']=$current['keterangan']; $row['conflicts']=$current['conflicts']; }
            }
            $row['valid']=$row['status']==='Valid';
        }
        unset($row);
        $valid=collect($rows)->where('valid',true)->count();
        return ['rows'=>$rows,'ids'=>$ids,'snapshot'=>$snapshot,'summary'=>['total_rows'=>count($rows),'valid_rows'=>$valid,'invalid_rows'=>count($rows)-$valid]];
    }

    public function preview(Request $request, Kelas $class, ClassMembershipService $service)
    {
        $data=$this->input($request,false); $path=$data['file']->getRealPath();
        $result=$this->resolve($this->read($path),$class,$data['tanggal_mulai'],$service);
        return ApiResponse::message('Preview import berhasil.', ['rows'=>$result['rows'],'summary'=>$result['summary'],'preview_token'=>$service->issue($result['snapshot'],(int)$request->user()->id,'excel:'.hash_file('sha256',$path))]);
    }

    public function import(Request $request, Kelas $class, ClassMembershipService $service)
    {
        $data=$this->input($request,true); $path=$data['file']->getRealPath();
        $result=$this->resolve($this->read($path),$class,$data['tanggal_mulai'],$service);
        abort_if($result['summary']['invalid_rows']>0,422,'Import dibatalkan. Perbaiki seluruh baris dan buat preview baru.');
        $members=$service->place($class,$result['ids'],$data['tanggal_mulai'],(int)$request->user()->id,$data['preview_token'],'excel:'.hash_file('sha256',$path));
        return ApiResponse::message('Import anggota kelas berhasil.', ['summary'=>['total_rows'=>count($members),'imported_rows'=>count($members),'skipped_rows'=>0,'rejected_rows'=>0], 'details'=>array_map(fn($r)=>['row'=>$r['row'],'nisn'=>$r['nisn'],'nama'=>$r['nama_excel'],'status'=>'Berhasil diimpor','keterangan'=>'Siswa ditambahkan.'],$result['rows'])]);
    }
}
