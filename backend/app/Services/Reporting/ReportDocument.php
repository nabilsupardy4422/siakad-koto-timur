<?php

namespace App\Services\Reporting;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;

/** Presentation only. Input must be the authorized result of ReportService::build(). */
final class ReportDocument
{
    public const SCHOOL = 'SMA Negeri 1 V Koto Timur';

    public static function metadata(string $type, User $user, array $report): array
    {
        $f = $report['effective_filters'];
        $scope = match ($f['scope']) {
            'teaching' => 'Penugasan mengajar sendiri',
            'homeroom' => 'Kelas binaan Wali Kelas',
            'own' => 'Data akademik pribadi',
            default => $user->role?->code === 'TU' ? 'Administrasi sekolah' : 'Monitoring sekolah',
        };
        $year = isset($f['academic_year_id']) ? TahunAkademik::find($f['academic_year_id']) : null;
        $period = $year ? "$year->tahun_mulai/$year->tahun_selesai · $year->semester" : (
            $type === 'teachers' ? 'Tidak berlaku' : 'Sesuai data / periode terpilih'
        );
        $labels = [];
        if (isset($f['class_id'])) {
            $class = Kelas::find($f['class_id']);
            if ($class) $labels[] = 'Kelas: '.$class->nama.' · Tingkat '.$class->tingkat;
        }
        if (isset($f['subject_id'])) $labels[] = 'Mapel: '.(Mapel::find($f['subject_id'])?->nama ?? '—');
        if (isset($f['teacher_id'])) $labels[] = 'Guru: '.(Guru::find($f['teacher_id'])?->nama_lengkap ?? '—');
        if (isset($f['student_id'])) $labels[] = 'Siswa: '.(Siswa::find($f['student_id'])?->nama_lengkap ?? '—');
        if (isset($f['schedule_id'])) {
            $schedule = JadwalPelajaran::with(['kelas', 'mapel', 'guru'])->find($f['schedule_id']);
            if ($schedule) $labels[] = 'Jadwal: '.$schedule->kelas?->nama.' / '.$schedule->mapel?->nama.' / '.$schedule->guru?->nama_lengkap.' / '.$schedule->hari.' '.$schedule->jam_mulai.'–'.$schedule->jam_selesai;
        }
        if (isset($f['semester'])) $labels[] = 'Semester: '.$f['semester'];
        if (isset($f['date_from']) || isset($f['date_to'])) $labels[] = 'Tanggal: '.($f['date_from'] ?? 'awal'). ' s.d. '.($f['date_to'] ?? 'akhir');
        if (isset($f['search'])) $labels[] = 'Pencarian: '.$f['search'];
        if (isset($f['status'])) $labels[] = 'Status akun: '.$f['status'];
        foreach (['date'=>'Tanggal WIB','month'=>'Bulan','level'=>'Tingkat'] as $key=>$label) if (isset($f[$key])) $labels[]=$label.': '.$f[$key];
        $note = match ($type) {
            'attendance' => 'Satu baris = satu catatan siswa per pertemuan. Tidak mewakili seluruh pertemuan yang direncanakan.',
            'grades' => 'Nilai komponen tercatat; bobot hanya informasi. Bukan nilai akhir atau rapor resmi.',
            'semester-recap' => 'H/I/S/A = Hadir/Izin/Sakit/Alpa. Persentase hadir dari catatan tersedia. Rata-rata komponen tanpa bobot; bukan rapor. Kosong berarti belum ada data.',
            'students' => in_array($f['scope'], ['teaching', 'homeroom'], true) ? 'Daftar anggota aktif pada tanggal pembuatan laporan.' : 'Keanggotaan sesuai filter dan riwayat yang tercatat.',
            'schedules' => 'Jadwal pelajaran yang tercatat, bukan bukti pelaksanaan pertemuan.',
            default => 'Dokumen informasi akademik sesuai kewenangan pengguna.',
        };
        if(isset($report['note'])) $note=$report['note'];
        if(isset($report['summary'])) $note.=' Ringkasan: '.implode('; ',array_map(fn($k,$v)=>$k.': '.($v??'—'),array_keys($report['summary']),array_values($report['summary']))).'.';
        if(!empty($report['warnings'])) $note.=' '.implode(' ', $report['warnings']);
        $actor = $user->role?->code === 'GURU' ? ($user->guru?->nama_lengkap ?? $user->name) : ($user->siswa?->nama_lengkap ?? $user->name);
        return [
            'school' => self::SCHOOL, 'title' => $report['title'], 'scope' => $scope,
            'period' => $period, 'filters' => $labels ? implode(' | ', $labels) : 'Seluruh data dalam cakupan laporan',
            'generated' => now()->format('d/m/Y H:i').' · '.$actor,
            'count' => $report['rows']->count(), 'note' => $note,
            'sheet' => match ($type) {
                'students' => 'Data Siswa', 'teachers' => 'Data Guru', 'classes' => 'Data Kelas',
                'schedules' => 'Jadwal', 'attendance' => 'Presensi', 'grades' => 'Penilaian', 'attendance-daily'=>'Harian','attendance-monthly'=>'Bulanan','attendance-coverage'=>'Kelengkapan Presensi','grade-coverage'=>'Kelengkapan Nilai','grade-components'=>'Komponen', default => 'Rekap Semester',
            },
        ];
    }

    /** Compact PDF projection, keeping per-row context in repeating group headings. */
    public static function sections(string $type, array $report): array
    {
        $grouped = in_array($type, ['students', 'schedules', 'attendance', 'grades', 'semester-recap'], true);
        $groups = $grouped ? $report['rows']->groupBy(fn (array $r) => json_encode([$r['kelas'], $r['tingkat'], $r['tahun_akademik'], $r['semester']], JSON_THROW_ON_ERROR)) : collect(['all' => $report['rows']]);
        if ($groups->isEmpty()) $groups = collect(['all' => collect()]);
        $sections = [];
        foreach ($groups as $rows) {
            $first = $rows->first();
            $context = $grouped && $first !== null ? implode(' · ', [
                $first['kelas'] ?? 'Belum ditempatkan', 'Tingkat '.($first['tingkat'] ?? '—'),
                $first['tahun_akademik'] ?? '—', $first['semester'] ?? '—',
            ]) : 'Data laporan';
            [$headings, $widths] = match ($type) {
                'students' => [['Siswa / identitas', 'Tanggal mulai', 'Tanggal selesai', 'Status'], [46,18,18,18]],
                'schedules' => [['Hari','Jam mulai','Jam selesai','Mata pelajaran','Guru'], [15,13,13,32,27]],
                'attendance' => [['Tanggal','Siswa / identitas','Mata pelajaran / guru','Jam','Status','Catatan'], [12,25,21,12,10,20]],
                'grades' => [['Siswa / identitas','Mata pelajaran / guru','Hari / jam','Komponen','Bobot','Nilai','Catatan'], [24,20,14,14,7,7,14]],
                'semester-recap' => [['Siswa / identitas','H','I','S','A','Total catatan','Hadir (%)','Jumlah nilai','Rata-rata komponen'], [28,5,5,5,5,12,12,12,16]],
                'attendance-daily'=>[$report['headings'],[9,22,7,10,8,7,7,7,23]],
                'attendance-monthly'=>[$report['headings'],[17,10,9,9,5,10,5,7,4,4,4,4,6,6]],
                'attendance-coverage'=>[$report['headings'],[5,13,18,18,12,10,10,14]],
                'grade-coverage'=>[$report['headings'],[5,10,15,15,13,7,5,5,6,6,6,7]],
                'grade-components'=>[$report['headings'],[7,13,18,18,7,14,6,9,8]],
                default => [$report['headings'], array_fill(0,count($report['headings']),100/count($report['headings']))],
            };
            $projected = $rows->map(function (array $r) use ($type): array {
                $identity = fn () => ($r['nama_siswa'] ?? $r['siswa'] ?? '—')."\nNIS ".($r['nis'] ?? '—')."\nNISN ".($r['nisn'] ?? '—');
                $subject = fn () => ($r['mata_pelajaran'] ?? '—')."\n".($r['guru'] ?? '—');
                $time = fn () => ($r['jam_mulai'] ?? '—').'–'.($r['jam_selesai'] ?? '—');
                return match ($type) {
                    'students' => [$identity(),$r['tanggal_mulai'],$r['tanggal_selesai'],$r['status_keanggotaan']],
                    'schedules' => [$r['hari'],$r['jam_mulai'],$r['jam_selesai'],$r['mata_pelajaran'],$r['guru']],
                    'attendance' => [$r['tanggal'],$identity(),$subject(),$time(),$r['status'],$r['catatan']],
                    'grades' => [$identity(),$subject(),$r['hari']."\n".$time(),$r['komponen'],$r['bobot'],$r['nilai'],$r['catatan']],
                    'semester-recap' => [$identity(),$r['hadir'],$r['izin'],$r['sakit'],$r['alpa'],$r['total_presensi'],$r['persentase_hadir'],$r['jumlah_nilai'],$r['rata_rata_nilai']],
                    default => array_values($r),
                };
            })->values();
            $sections[] = compact('context','headings','widths','projected');
        }
        return $sections;
    }

    public static function logo(): ?string
    {
        $path = resource_path('images/report-school-logo.png');
        return is_readable($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }
}
