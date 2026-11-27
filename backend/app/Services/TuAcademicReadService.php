<?php

namespace App\Services;

use App\Models\TahunAkademik;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/** Read-only TU projections. Existing teaching and report contracts are untouched. */
final class TuAcademicReadService
{
    public static function context(Request $request, bool $attendance): array
    {
        abort_unless($request->user()?->role?->code === 'TU', 403);
        $rules = [
            'academic_year_id'=>'required|integer|min:1', 'class_id'=>'nullable|integer|min:1',
            'level'=>'nullable|in:10,11,12', 'subject_id'=>'nullable|integer|min:1',
            'teacher_id'=>'nullable|integer|min:1', 'schedule_id'=>'nullable|integer|min:1',
            'view'=>'required|in:'.($attendance?'catalog,daily,coverage,detail':'catalog,coverage,detail,components'),
            'page'=>'nullable|integer|min:1|max:100000', 'search'=>'nullable|string|max:100',
        ];
        if ($attendance) $rules['date']='required_unless:view,catalog|nullable|date_format:Y-m-d';
        else $rules['component_id']='nullable|integer|min:1';
        $d=$request->validate($rules);
        abort_if(count(array_diff(array_keys($request->query()),array_keys($rules)))>0,422,'Filter tidak didukung.');
        if($d['view']==='catalog') abort_if(count(array_diff(array_keys($d),['academic_year_id','view']))>0,422,'Katalog hanya menerima periode.');
        if($d['view']!=='detail') abort_if(isset($d['search'])||isset($d['component_id']),422,'Pencarian siswa dan komponen hanya untuk detail.');
        return self::contextFor($request->user(),$d,$attendance);
    }
    /** Call only with validated, type-specific filters. */
    public static function contextFor(\App\Models\User $user,array $d,bool $attendance): array
    {
        abort_unless($user->role?->code==='TU',403);
        $year=TahunAkademik::findOrFail($d['academic_year_id']);
        $now=Carbon::now('Asia/Jakarta');
        $date=$attendance?($d['date']??$now->toDateString()):$now->toDateString();
        abort_if($attendance && $d['view']!=='catalog' && ((int)substr($date,0,4)<(int)$year->tahun_mulai || (int)substr($date,0,4)>(int)$year->tahun_selesai),422,'Tanggal di luar tahun kalender periode.');
        $classes=self::bounded(DB::table('kelas')->where('tahun_akademik_id',$year->id)->select('id','tahun_akademik_id','nama','tingkat')->orderBy('tingkat')->orderBy('nama')->orderBy('id'),5000);
        $schedules=self::bounded(DB::table('jadwal_pelajaran as j')->join('kelas as k','k.id','=','j.kelas_id')
            ->join('mapel as m','m.id','=','j.mapel_id')->join('guru as g','g.id','=','j.guru_id')
            ->where('j.tahun_akademik_id',$year->id)->where('k.tahun_akademik_id',$year->id)
            ->select('j.id','j.kelas_id','j.mapel_id','j.guru_id','j.hari','j.jam_mulai','j.jam_selesai','k.nama as class_name','k.tingkat','m.nama as subject_name','g.nama_lengkap as teacher_name')
            ->orderBy('k.tingkat')->orderBy('k.nama')->orderBy('j.id'),10000);
        $selected=$classes->filter(fn($c)=>(!isset($d['level'])||(int)$c->tingkat===(int)$d['level'])&&(!isset($d['class_id'])||(int)$c->id===(int)$d['class_id']));
        abort_if(isset($d['class_id'])&&$selected->isEmpty(),422,'Rombel tidak sesuai periode/tingkat.');
        $scoped=$schedules->whereIn('kelas_id',$selected->pluck('id'))->filter(fn($s)=>(!isset($d['subject_id'])||(int)$s->mapel_id===(int)$d['subject_id'])&&(!isset($d['teacher_id'])||(int)$s->guru_id===(int)$d['teacher_id'])&&(!isset($d['schedule_id'])||(int)$s->id===(int)$d['schedule_id']));
        abort_if((isset($d['subject_id'])||isset($d['teacher_id'])||isset($d['schedule_id']))&&$scoped->isEmpty(),422,'Jadwal tidak sesuai filter akademik.');
        $active=(bool)$year->is_active && TahunAkademik::where('is_active',true)->count()===1;
        return compact('d','year','now','date','classes','schedules','selected','scoped','active');
    }
    public static function bounded($query,int $limit)
    {
        $rows=$query->limit($limit+1)->get();
        abort_if($rows->count()>$limit,422,'Data melebihi batas pemeriksaan. Persempit filter; data tidak dipotong.');
        return $rows;
    }
    public static function members($classIds, ?string $date=null)
    {
        $q=DB::table('anggota_kelas as a')->join('siswa as s','s.id','=','a.siswa_id')->whereIn('a.kelas_id',$classIds)
            ->select('a.id','a.kelas_id','a.siswa_id','a.tanggal_mulai','a.tanggal_selesai','s.nama_lengkap','s.nisn','s.nipd','s.nis');
        if($date!==null) $q->whereDate('a.tanggal_mulai','<=',$date)->where(fn($q)=>$q->whereNull('a.tanggal_selesai')->orWhereDate('a.tanggal_selesai','>=',$date));
        return self::bounded($q->orderBy('a.id'),30000)->map(
            fn ($member) => self::normalizeMemberDates($member)
        );
    }
    /** Query Builder bypasses Eloquent date casts. Keep calendar dates, not instants. */
    public static function calendarDate($value): ?string
    {
        if (!is_string($value) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:[ T](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?)?\z/', $value, $parts)) {
            return null;
        }
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
            ? substr($value, 0, 10) : null;
    }

    public static function normalizeMemberDates(object $member): object
    {
        // Preserve malformed values so they remain anomalies, never open-ended memberships.
        foreach (['tanggal_mulai', 'tanggal_selesai'] as $field) {
            $member->$field = self::calendarDate($member->$field) ?? $member->$field;
        }
        return $member;
    }

    public static function validInterval($m): bool
    {
        $start = self::calendarDate($m->tanggal_mulai);
        $end = self::calendarDate($m->tanggal_selesai);
        return $start !== null && ($m->tanggal_selesai === null || ($end !== null && $end >= $start));
    }
    public static function student($r): array {return ['student_id'=>(int)$r->siswa_id,'student_name'=>$r->nama_lengkap,'nisn'=>$r->nisn,'nipd'=>$r->nipd,'nis'=>$r->nis];}
    public static function matches($r,string $search): bool {return $search===''||str_contains(mb_strtolower(implode(' ',[$r['student_name']??'', $r['nisn']??'', $r['nipd']??'', $r['nis']??''])),mb_strtolower($search));}
    public static function result(array $c,array $summary,array $rows,array $warnings=[])
    {
        $page=(int)($c['d']['page']??1);$total=count($rows);$last=max(1,(int)ceil($total/20));
        abort_if($page>$last,422,'Halaman tidak tersedia. Muat ulang dari halaman pertama.');
        return response()->json(['data'=>['summary'=>$summary,'rows'=>array_slice(array_values($rows),($page-1)*20,20),'warnings'=>array_values(array_unique($warnings))], 'meta'=>['academic_year_id'=>(int)$c['year']->id,'semester'=>$c['year']->semester,'view'=>$c['d']['view'],'filters'=>$c['d'],'date'=>$c['date'],'active'=>$c['active'],'total'=>$total,'page'=>$page,'last_page'=>$last,'per_page'=>20]])->header('Cache-Control','private, no-store');
    }
    public static function catalog(array $c)
    {
        return response()->json(['data'=>['classes'=>$c['classes']->values(),'schedules'=>$c['schedules']->values()], 'meta'=>['academic_year_id'=>(int)$c['year']->id,'semester'=>$c['year']->semester,'active'=>$c['active']]])->header('Cache-Control','private, no-store');
    }
}
