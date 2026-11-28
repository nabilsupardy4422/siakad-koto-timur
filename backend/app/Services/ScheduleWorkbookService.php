<?php
namespace App\Services;

use App\Exports\ScheduleExport;
use App\Models\{Guru,Kelas,Mapel,TahunAkademik};
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

final class ScheduleWorkbookService
{
    private static function rows(Worksheet $sheet, array $rows, int $start = 1): void
    {
        foreach ($rows as $index => $row) {
            foreach (array_values($row) as $column => $value) {
                $sheet->setCellValueExplicit([$column+1,$start+$index], (string)$value, DataType::TYPE_STRING);
            }
        }
    }

    private static function style(Worksheet $sheet, int $header, array $widths): void
    {
        $last = $sheet->getHighestColumn(); $end = $sheet->getHighestRow();
        $sheet->getStyle("A{$header}:{$last}{$header}")->applyFromArray([
            'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
            'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'173B62']],
        ]);
        $sheet->getRowDimension($header)->setRowHeight(24);
        foreach ($widths as $i=>$width) $sheet->getColumnDimensionByColumn($i+1)->setWidth($width);
        $sheet->getStyle("A{$header}:{$last}{$end}")->getAlignment()->setWrapText(true)->setVertical('center');
        for ($i=$header+1;$i<=$end;$i++) {
            $sheet->getRowDimension($i)->setRowHeight(32);
            if ($i%2===0) $sheet->getStyle("A{$i}:{$last}{$i}")->getFill()->setFillType('solid')->getStartColor()->setRGB('EDF3F8');
        }
        $sheet->freezePane('A'.($header+1));
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setPrintArea("A1:{$last}{$end}")->setRowsToRepeatAtTopByStartAndEnd($header,$header);
    }

    private static function file(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(),'siakad-jadwal-');
        if ($path === false) throw new \RuntimeException('File sementara tidak tersedia.');
        try { (new Xlsx($book))->save($path); return $path; }
        catch (\Throwable $e) { @unlink($path); throw $e; }
        finally { $book->disconnectWorksheets(); }
    }

    public static function template(TahunAkademik $period): string
    {
        $book = new Spreadsheet(); $sheet=$book->getActiveSheet(); $sheet->setTitle('Input Jadwal');
        self::rows($sheet, [(new ScheduleExport())->headings()]);
        self::style($sheet,1,[20,15,12,23,20,25,15,15,15]);
        foreach (['A','B','D','E','F','G','H','I'] as $column) $sheet->getStyle($column.'2:'.$column.'1001')->getNumberFormat()->setFormatCode('@');
        $groups = [
            'Referensi Guru'=>[ ['NIP','Nama guru','Status'], ...Guru::with('user.role')->orderBy('nama_lengkap')->get()->filter(fn($g)=>ScheduleWriteService::teacherEligible($g))->map(fn($g)=>[$g->nip,$g->nama_lengkap,$g->user->requires_activation?'Menunggu aktivasi':'Siap'])->values()->all() ],
            'Referensi Mapel'=>[['Kode','Nama mata pelajaran'], ...Mapel::orderBy('kode')->get()->map(fn($m)=>[$m->kode,$m->nama])->all()],
            'Referensi Kelas'=>[['Tingkat','Nama rombel'], ...Kelas::where('tahun_akademik_id',$period->id)->orderBy('tingkat')->orderBy('nama')->get()->map(fn($k)=>[$k->tingkat,$k->nama])->all()],
            'Petunjuk'=>[['Kolom','Ketentuan'],['tahun_akademik',$period->tahun_mulai.'/'.$period->tahun_selesai],['semester',$period->semester],['NIP','Salin sebagai teks agar nol awal tidak hilang.'],['Hari','SENIN, SELASA, RABU, KAMIS, JUMAT, SABTU, MINGGU'],['Jam','HH:MM, contoh 07:00'],['Input Jadwal','Isi hanya worksheet pertama. Maksimal 1000 baris. Tidak menggunakan formula.'],['Import','Preview, konfirmasi; hanya menambah data.']]
        ];
        foreach ($groups as $name=>$rows) { $s=$book->createSheet(); $s->setTitle($name); self::rows($s,$rows); self::style($s,1,[28,70,24]); }
        $book->setActiveSheetIndex(0); return self::file($book);
    }

    public static function document(TahunAkademik $period, Collection $items, string $kind): string
    {
        $first=$items->first();
        $teacher=$kind==='teacher';
        $subject=$teacher ? $first->guru->nama_lengkap : $first->kelas->tingkat.' '.$first->kelas->nama;
        $book=new Spreadsheet(); $sheet=$book->getActiveSheet(); $sheet->setTitle('Jadwal');
        $minutes=0; $rows=[];
        foreach ($items as $item) {
            $start=substr($item->jam_mulai,0,5); $end=substr($item->jam_selesai,0,5);
            $toMinutes=fn($time)=>(int)substr($time,0,2)*60+(int)substr($time,3,2);
            $minutes+=$toMinutes($end)-$toMinutes($start);
            $rows[]=[$item->hari,$start,$end,$item->mapel->nama,$teacher?$item->kelas->tingkat.' '.$item->kelas->nama:$item->guru->nama_lengkap];
        }
        self::rows($sheet,[['SMA NEGERI 1 V KOTO TIMUR'],[$teacher?'JADWAL MENGAJAR GURU':'JADWAL PELAJARAN ROMBEL'],[$period->tahun_mulai.'/'.$period->tahun_selesai.' - '.$period->semester],[$subject],[$teacher?'NIP: '.$first->guru->nip.' | '.(ScheduleWriteService::teacherEligible($first->guru)?($first->guru->user->requires_activation?'Menunggu aktivasi':'Siap'):'PERHATIAN: akun tidak memenuhi syarat'):'Rombel'],['Jumlah jadwal: '.$items->count().' | Durasi: '.$minutes.' menit/minggu'],['Hari','Mulai','Selesai','Mata pelajaran',$teacher?'Rombel':'Guru'],...$rows]);
        for($i=1;$i<=6;$i++) $sheet->mergeCells("A{$i}:E{$i}");
        $sheet->getStyle('A1:A2')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('173B62');
        $sheet->getRowDimension(1)->setRowHeight(24); $sheet->getRowDimension(2)->setRowHeight(24);
        self::style($sheet,7,[18,13,13,40,36]);
        // Preserve the existing document cell contract, improve screen and print layout.
        $last=$sheet->getHighestRow();
        $sheet->setShowGridlines(false);
        $sheet->getStyle("A1:E{$last}")->getFont()->setName('Calibri')->setSize(11);
        $sheet->getStyle('A1:E2')->getFill()->setFillType('solid')->getStartColor()->setRGB('173B62');
        $sheet->getStyle('A1:E2')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:E6')->getAlignment()->setVertical('center')->setWrapText(true);
        $sheet->getStyle('A3:E6')->getFill()->setFillType('solid')->getStartColor()->setRGB('EAF1F7');
        $sheet->getStyle('A4:E4')->getFont()->setBold(true)->setSize(13);
        foreach([1=>30,2=>30,3=>24,4=>30,5=>30,6=>24] as $row=>$height) $sheet->getRowDimension($row)->setRowHeight($height);
        $sheet->getStyle("A7:E{$last}")->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('D6E1EB');
        $sheet->getStyle("B8:C{$last}")->getAlignment()->setHorizontal('center');
        $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.35)->setRight(0.35);
        $sheet->getHeaderFooter()->setOddFooter('&LAdministrasi Tata Usaha&RHalaman &P dari &N');
        return self::file($book);
    }

    public static function distribution(TahunAkademik $period, string $kind, ?int $id, bool $includeInactive): array
    {
        $query=(new ScheduleExport(['academic_year_id'=>$period->id]))->query()->reorder()->with('guru.user.role');
        if ($kind==='teacher') $query->where('guru_id',$id);
        if ($kind==='class') $query->where('kelas_id',$id);
        $items=$query->orderByRaw("CASE UPPER(hari) WHEN 'SENIN' THEN 1 WHEN 'SELASA' THEN 2 WHEN 'RABU' THEN 3 WHEN 'KAMIS' THEN 4 WHEN 'JUMAT' THEN 5 WHEN 'SABTU' THEN 6 WHEN 'MINGGU' THEN 7 ELSE 8 END")->orderBy('jam_mulai')->orderBy('id')->get();
        if ($kind!=='batch') {
            abort_if($items->isEmpty(),422,'Tidak ada jadwal pada periode tersebut.');
            return [self::document($period,$items,$kind), 'jadwal-'.$kind.'-'.$id.'.xlsx'];
        }
        $groups=$items->groupBy('guru_id');
        $omitted=[];
        if (!$includeInactive) $groups=$groups->filter(function($rows) use (&$omitted) { $g=$rows->first()->guru; if(ScheduleWriteService::teacherEligible($g)) return true; $omitted[]=$g->nama_lengkap; return false; });
        abort_if($groups->isEmpty(),422,'Tidak ada guru yang memenuhi pilihan export.');
        abort_if($groups->count()>250,422,'Maksimal 250 guru dalam satu export.');
        $path=tempnam(sys_get_temp_dir(),'siakad-zip-'); if($path===false) throw new \RuntimeException('File sementara tidak tersedia.');
        $zip=new ZipArchive(); $files=[]; $opened=false;
        try {
            if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('ZIP tidak dapat dibuat.');
            $opened=true;
            foreach ($groups as $id=>$rows) {
                $file=self::document($period,$rows,'teacher'); $files[]=$file;
                $name=trim(preg_replace('/[^a-zA-Z0-9_-]+/','_',$rows->first()->guru->nama_lengkap),'_');
                if(!$zip->addFile($file,'Jadwal_'.substr($name,0,80).'_'.$id.'.xlsx')) throw new \RuntimeException('Gagal menambahkan Excel.');
            }
            if($omitted) $zip->addFromString('PERHATIAN.txt',"Guru tidak memenuhi syarat dan tidak disertakan:\n".implode("\n",$omitted));
            $closed=$zip->close(); $opened=false;
            if(!$closed) throw new \RuntimeException('Gagal menyelesaikan ZIP.');
            return [$path,'jadwal-mengajar-'.$period->id.'.zip'];
        } catch (\Throwable $e) { if($opened) $zip->close(); @unlink($path); throw $e; }
        finally { foreach($files as $file) @unlink($file); }
    }
}
