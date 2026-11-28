<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaliKelasRequest;
use App\Http\Requests\UpdateWaliKelasRequest;
use App\Http\Resources\WaliKelasResource;
use App\Models\User;
use App\Models\WaliKelas;
use App\Support\ApiResponse;
use App\Support\HomeroomReadAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\HomeroomAssignmentService;
use App\Models\Kelas;
use App\Models\TahunAkademik;

class WaliKelasController extends Controller
{
    /**
     * Menampilkan daftar assignment Wali Kelas.
     *
     * TU dan Kepala Sekolah dapat melihat seluruh assignment.
     * Guru hanya dapat melihat assignment miliknya.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
        ]);

        $query = WaliKelas::query()
            ->with([
                'guru',
                'kelas',
                'tahunAkademik',
            ])
            ->latest('id');

        $this->applyViewScope(
            $query,
            $user
        );

        if ($request->input('scope') === 'homeroom') {
            $year = HomeroomReadAccess::activeYear($user);
            if ($year === null) {
                $query->whereRaw('1 = 0');
            } else {
                HomeroomReadAccess::effective($query, (int) $user->guru->id, $year);
                $query->whereHas('kelas', fn (Builder $class) => $class->where('tahun_akademik_id', $year));
            }
        }

        if ($request->filled('academic_year_id')) {
            $query->where(
                'tahun_akademik_id',
                $request->integer('academic_year_id')
            );
        }

        if ($request->filled('class_id')) {
            $query->where(
                'kelas_id',
                $request->integer('class_id')
            );
        }

        if ($request->filled('teacher_id')) {
            $query->where(
                'guru_id',
                $request->integer('teacher_id')
            );
        }

        $assignments = $query
            ->paginate(
                $request->integer('per_page', 20)
            )
            ->withQueryString();

        return ApiResponse::collection(
            WaliKelasResource::collection(
                $assignments
            ),
            [
                'current_page' => $assignments->currentPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'last_page' => $assignments->lastPage(),
            ]
        );
    }

    /**
     * Membuat assignment Wali Kelas.
     *
     * Route dibatasi untuk TU.
     * Validasi dilakukan oleh StoreWaliKelasRequest.
     */
    public function store(
        StoreWaliKelasRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $assignment = app(HomeroomAssignmentService::class)->create($validated);

        $assignment->load([
            'guru',
            'kelas',
            'tahunAkademik',
        ]);

        return ApiResponse::message(
            'Data Wali Kelas berhasil ditambahkan.',
            new WaliKelasResource(
                $assignment
            ),
            201
        );
    }

    /**
     * Menampilkan detail assignment Wali Kelas.
     *
     * Guru hanya dapat melihat assignment miliknya.
     */
    public function show(
        Request $request,
        WaliKelas $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
        ]);

        if (! $this->canViewAssignment(
            $assignment,
            $user
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $assignment->load([
            'guru',
            'kelas',
            'tahunAkademik',
        ]);

        return ApiResponse::success(
            new WaliKelasResource(
                $assignment
            )
        );
    }

    /**
     * Memperbarui assignment Wali Kelas.
     *
     * Route dibatasi untuk TU.
     * Validasi dilakukan oleh UpdateWaliKelasRequest.
     */
    public function update(
        UpdateWaliKelasRequest $request,
        WaliKelas $assignment
    ): JsonResponse {
        $validated = $request->validated();

        $assignment = app(HomeroomAssignmentService::class)->update($assignment, $validated);

        $assignment->refresh()->load([
            'guru',
            'kelas',
            'tahunAkademik',
        ]);

        return ApiResponse::message(
            'Data Wali Kelas berhasil diperbarui.',
            new WaliKelasResource(
                $assignment
            )
        );
    }

    /**
     * Mengakhiri assignment Wali Kelas.
     *
     * Route dibatasi untuk TU.
     * Assignment tidak dihapus agar riwayat tetap tersedia.
     */
    public function destroy(Request $request, WaliKelas $assignment): JsonResponse
    {
        $data = $request->validate(['version' => ['sometimes', 'required', 'string', 'size:64'], 'as_of'=>['sometimes','required','date_format:Y-m-d']]);
        app(HomeroomAssignmentService::class)->end($assignment, $data['version'] ?? null, $data['as_of'] ?? null);
        return ApiResponse::message('Penugasan Wali Kelas berhasil diakhiri.');
    }

    public function replace(Request $request, WaliKelas $assignment): JsonResponse
    {
        $data = $request->validate(['guru_id'=>['required','integer','exists:guru,id'], 'version'=>['required','string','size:64'], 'as_of'=>['sometimes','required','date_format:Y-m-d']]);
        $new = app(HomeroomAssignmentService::class)->replace($assignment, (int) $data['guru_id'], $data['version'], $data['as_of'] ?? null);
        return ApiResponse::message('Pergantian Wali Kelas berhasil.', new WaliKelasResource($new->load(['guru','kelas','tahunAkademik'])), 201);
    }

    public function cancel(Request $request, WaliKelas $assignment): JsonResponse
    {
        $data = $request->validate(['version'=>['required','string','size:64'], 'as_of'=>['required','date_format:Y-m-d'], 'reason'=>['required','string','max:500']]);
        app(HomeroomAssignmentService::class)->cancel($assignment, $data['version'], $data['as_of'], (int)$request->user()->id, $data['reason']);
        return ApiResponse::message('Penugasan dibatalkan. Riwayat tetap tersimpan.');
    }

    public function continuation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id'=>['required','integer','exists:tahun_akademik,id'],
            'target_id'=>['required','integer','exists:tahun_akademik,id'],
            'tanggal_mulai'=>['required','date_format:Y-m-d'],
            'rows'=>['required','array','min:1','max:100'],
            'rows.*.assignment_id'=>['required','integer','distinct','exists:wali_kelas,id'],
            'rows.*.class_id'=>['required','integer','distinct','exists:kelas,id'],
            'token'=>['sometimes','required','string','max:256'],
        ]);
        $service = app(\App\Services\HomeroomContinuationService::class);
        return ApiResponse::success($service->run($data, (int)$request->user()->id))->header('Cache-Control','private, no-store');
    }

    public function workspace(Request $request): JsonResponse
    {
        $data = $request->validate(['academic_year_id'=>['required','integer','exists:tahun_akademik,id']]);
        return ApiResponse::success(app(HomeroomAssignmentService::class)->workspace(TahunAkademik::findOrFail($data['academic_year_id'])))
            ->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['class_id'=>['required','integer','exists:kelas,id'], 'tanggal_mulai'=>['required','date_format:Y-m-d'], 'replace_id'=>['sometimes','required','integer','exists:wali_kelas,id']]);
        return ApiResponse::success(app(HomeroomAssignmentService::class)->candidates(Kelas::findOrFail($data['class_id']), $data['tanggal_mulai'], isset($data['replace_id']) ? (int) $data['replace_id'] : null))
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Membatasi daftar assignment berdasarkan role.
     */
    private function applyViewScope(
        Builder $query,
        User $user
    ): void {
        $role = $user->role?->code;

        if (in_array(
            $role,
            [
                'TU',
                'KEPALA_SEKOLAH',
            ],
            true
        )) {
            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(
                'guru_id',
                $guruId
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * Memeriksa akses detail assignment.
     */
    private function canViewAssignment(
        WaliKelas $assignment,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (in_array(
            $role,
            [
                'TU',
                'KEPALA_SEKOLAH',
            ],
            true
        )) {
            return true;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            return $guruId !== null
                && (int) $assignment->guru_id === (int) $guruId;
        }

        return false;
    }
}
