<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassMemberRequest;
use App\Http\Resources\ClassMemberResource;
use App\Models\AnggotaKelas;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\User;
use App\Support\ApiResponse;
use App\Services\ClassMembershipService;
use App\Support\HomeroomReadAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;


class ClassMemberController extends Controller
{
    public function index(
        Request $request,
        Kelas $class
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'siswa', 'guru']);

        if ($request->input('scope') === 'homeroom') {
            HomeroomReadAccess::authorizeClass($user, $class);
            if ($request->filled('academic_year_id')) {
                abort_unless($request->integer('academic_year_id') === (int) $class->tahun_akademik_id, 403);
            }
        }

        if (! $this->canViewClass($class, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $today = Carbon::today('Asia/Jakarta');
        $status = $request->input('membership_status') ?? 'effective';
        $allMemberships = $status === 'all';
        $admin = $user->role?->code === 'TU';
        if ($admin) $request->validate(['membership_status'=>'nullable|in:all,effective,scheduled,historical,conflict','search'=>'nullable|string|max:100','sort_order'=>'nullable|in:asc,desc','as_of'=>'nullable|date_format:Y-m-d']);
        if (!$admin) abort_if(!in_array($status,['effective','all'],true),403);
        // Alternate date is administrative read-only, never an authorization input.
        if ($admin && $request->filled('as_of')) $today=Carbon::parse($request->input('as_of'),'Asia/Jakarta');
        if ($allMemberships) {
            abort_unless($user->role?->code === 'TU' || $request->input('scope') === 'homeroom', 403);
        }

        $query = AnggotaKelas::query()
            ->with('siswa.user')
            ->where('kelas_id', $class->id);
        $summary = null;
        $conflictIds = [];
        if ($admin) {
            $history = AnggotaKelas::whereHas('kelas',fn($q)=>$q->where('tahun_akademik_id',$class->tahun_akademik_id))->get()->groupBy('siswa_id');
            foreach($history as $records) foreach($records as $i=>$m) {
                $a=$m->tanggal_mulai?->toDateString();$b=$m->tanggal_selesai?->toDateString();
                if(!$a||($b&&$b<$a)) $conflictIds[$m->id]=true;
                foreach($records->slice($i+1) as $other) if(\App\Support\AcademicTransitionRules::overlap($a,$b,$other->tanggal_mulai?->toDateString(),$other->tanggal_selesai?->toDateString())) { $conflictIds[$m->id]=true;$conflictIds[$other->id]=true; }
            }
            $conflictIds=array_keys($conflictIds);
        }
        if ($user->role?->code === 'TU') {
            $valid = (clone $query)->whereNotNull('tanggal_mulai')->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereColumn('tanggal_selesai', '>=', 'tanggal_mulai'));
            $summary = [
                'total_records' => (clone $query)->count(),
                'effective_records' => (clone $valid)->whereDate('tanggal_mulai', '<=', $today)->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $today))->count(),
                'scheduled_records' => (clone $valid)->whereDate('tanggal_mulai', '>', $today)->count(),
                'historical_records' => (clone $valid)->whereDate('tanggal_selesai', '<', $today)->count(),
                'conflict_records'=>(clone $query)->whereIn('id',$conflictIds)->count(),
            ];
        }
        if ($admin && $status === 'conflict') $query->whereIn('id',$conflictIds);
        elseif ($admin && in_array($status,['scheduled','historical'],true)) {
            $query->whereNotNull('tanggal_mulai')->where(fn($q)=>$q->whereNull('tanggal_selesai')->orWhereColumn('tanggal_selesai','>=','tanggal_mulai'));
            $status==='scheduled' ? $query->whereDate('tanggal_mulai','>',$today) : $query->whereDate('tanggal_selesai','<',$today);
        } elseif (! $allMemberships) {
            $query->whereDate('tanggal_mulai', '<=', $today)
                ->where(function (Builder $query) use ($today): void {
                    $query->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $today);
                });
        }

        /*
         * Siswa hanya dapat melihat keanggotaan dirinya sendiri.
         */
        if ($user->role?->code === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return response()->json([
                    'message' => 'Data siswa tidak ditemukan.',
                ], 403);
            }

            $query->where('siswa_id', $studentId);
        }

        if ($admin && ($keyword=trim((string)$request->input('search','')))!=='') {
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($keyword)).'%';
            $query->whereHas('siswa',fn($q)=>$q->whereRaw("LOWER(nama_lengkap) LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("nisn LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("nipd LIKE ? ESCAPE '!'",[$pattern]));
        }
        if($admin && $request->filled('sort_order')) $query->orderBy(Siswa::select('nama_lengkap')->whereColumn('siswa.id','anggota_kelas.siswa_id'),$request->input('sort_order'))->orderBy('id');
        else $query->latest('id');
        $members = $query
            ->paginate(max(1, min(100, $request->integer('per_page', 15))))
            ->withQueryString();

        if($admin) foreach($members as $member) $member->setAttribute('has_conflict',in_array($member->id,$conflictIds));
        return ApiResponse::collection(
            ClassMemberResource::collection($members->items()),
            [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
                ...($summary !== null ? ['summary' => $summary] : []),
                ...($allMemberships || $user->role?->code === 'TU' ? ['as_of' => $today->toDateString()] : []),
            ]
        );
    }

    public function store(
        StoreClassMemberRequest $request,
        Kelas $class
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canManageClassMembers($class, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah anggota kelas ini.',
            ], 403);
        }

        $validated = $request->validated();

        $membership = app(ClassMembershipService::class)->place($class, [(int)$validated['siswa_id']], Carbon::parse($validated['tanggal_mulai'])->toDateString(), (int)$user->id)[0];

        return ApiResponse::message(
            'Siswa berhasil ditambahkan ke kelas.',
            new ClassMemberResource(
                $membership->load('siswa')
            ),
            201
        );
    }

    public function destroy(
        Request $request,
        Kelas $class,
        Siswa $student
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canManageClassMembers($class, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah anggota kelas ini.',
            ], 403);
        }

        $data = $request->validate(['membership_id' => ['nullable', 'integer', 'min:1']]);
        app(ClassMembershipService::class)->end($class, $student, isset($data['membership_id']) ? (int)$data['membership_id'] : null);

        return ApiResponse::message(
            'Keanggotaan siswa pada kelas berhasil diakhiri.'
        );
    }

    private function canViewClass(
        Kelas $class,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (in_array(
            $role,
            ['TU', 'KEPALA_SEKOLAH'],
            true
        )) {
            return true;
        }

        $today = Carbon::today('Asia/Jakarta');

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return false;
            }

            return $class->anggotaKelas()
                ->where('siswa_id', $studentId)
                ->whereDate('tanggal_mulai', '<=', $today)
                ->where(function (
                    Builder $query
                ) use ($today): void {
                    $query
                        ->whereNull('tanggal_selesai')
                        ->orWhereDate(
                            'tanggal_selesai',
                            '>=',
                            $today
                        );
                })
                ->exists();
        }

        if ($role !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        /*
         * Guru mata pelajaran dapat melihat anggota kelas
         * apabila memiliki jadwal mengajar pada kelas tersebut.
         */
        $teachingClass = $class->jadwalPelajaran()
            ->where('guru_id', $guruId)
            ->where(
                'tahun_akademik_id',
                $class->tahun_akademik_id
            )
            ->exists();

        if ($teachingClass) {
            return true;
        }

        /*
         * Wali kelas hanya mendapat akses melalui assignment
         * yang berlaku pada tanggal hari ini.
         */
        return HomeroomReadAccess::currentAssignments((int) $guruId, (int) $class->tahun_akademik_id)
            ->where('kelas_id', $class->id)->exists();
    }

    private function canManageClassMembers(Kelas $class, User $user): bool
    {
        return $user->role?->code === 'TU';
    }
}
