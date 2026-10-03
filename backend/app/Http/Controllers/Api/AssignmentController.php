<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Guru;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $query = Assignment::query()
            ->with([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'komponenNilai',
            ])
            ->latest('deadline');

        $this->applyViewScope($query, $user);

        $assignments = $query
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            AssignmentResource::collection($assignments->items()),
            [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ]
        );
    }

    public function store(
        StoreAssignmentRequest $request
    ): JsonResponse {
        $assignment = DB::transaction(function () use ($request): Assignment {
            $validated = $request->validated();

            $assignment = new Assignment();

            $assignment->fill([
                'jadwal_pelajaran_id' => $validated['jadwal_pelajaran_id'],
                'komponen_nilai_id' => $validated['komponen_nilai_id'] ?? null,
                'judul' => $validated['judul'],
                'deskripsi' => $validated['deskripsi'] ?? null,
                'deadline' => $validated['deadline'],
                'created_by' => $request->user()->id,
            ]);

            if ($request->hasFile('file')) {
                $file = $request->file('file');

                $path = $file->store(
                    'assignments',
                    'local'
                );

                $assignment->file_path = $path;
                $assignment->file_name = $file->getClientOriginalName();
                $assignment->file_mime_type = $file->getMimeType();
                $assignment->file_size = $file->getSize();
            }

            $assignment->save();

            return $assignment;
        });

        return ApiResponse::message(
            'Tugas berhasil ditambahkan.',
            new AssignmentResource(
                $assignment->load([
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                    'komponenNilai',
                ])
            ),
            201
        );
    }

    public function show(
        Request $request,
        Assignment $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        if (! $this->canViewAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return ApiResponse::success(
            new AssignmentResource(
                $assignment->load([
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                    'komponenNilai',
                ])
            )
        );
    }

    public function update(
        UpdateAssignmentRequest $request,
        Assignment $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
        ]);

        if (! $this->canManageAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah tugas ini.',
            ], 403);
        }

        $oldFilePath = $assignment->file_path;

        DB::transaction(function () use (
            $request,
            $assignment
        ): void {
            $validated = $request->validated();

            $assignment->fill(
                collect($validated)
                    ->except([
                        'file',
                        'remove_file',
                    ])
                    ->toArray()
            );

            if (
                $request->boolean('remove_file') &&
                $assignment->file_path !== null
            ) {
                Storage::disk('local')->delete(
                    $assignment->file_path
                );

                $assignment->file_path = null;
                $assignment->file_name = null;
                $assignment->file_mime_type = null;
                $assignment->file_size = null;
            }

            if ($request->hasFile('file')) {
                if ($assignment->file_path !== null) {
                    Storage::disk('local')->delete(
                        $assignment->file_path
                    );
                }

                $file = $request->file('file');

                $assignment->file_path = $file->store(
                    'assignments',
                    'local'
                );

                $assignment->file_name =
                    $file->getClientOriginalName();

                $assignment->file_mime_type =
                    $file->getMimeType();

                $assignment->file_size =
                    $file->getSize();
            }

            $assignment->save();
        });

        if (
            $oldFilePath !== null &&
            $assignment->file_path !== $oldFilePath &&
            ! Storage::disk('local')->exists($oldFilePath)
        ) {
            // File lama sudah dihapus saat proses update.
        }

        return ApiResponse::message(
            'Tugas berhasil diperbarui.',
            new AssignmentResource(
                $assignment->refresh()->load([
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                    'komponenNilai',
                ])
            )
        );
    }

    public function destroy(
        Request $request,
        Assignment $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
        ]);

        if (! $this->canManageAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk menghapus tugas ini.',
            ], 403);
        }

        if ($assignment->submissionTugas()->exists()) {
            return response()->json([
                'message' => 'Tugas tidak dapat dihapus karena sudah memiliki submission siswa.',
            ], 422);
        }

        DB::transaction(function () use ($assignment): void {
            if ($assignment->file_path !== null) {
                Storage::disk('local')->delete(
                    $assignment->file_path
                );
            }

            $assignment->delete();
        });

        return ApiResponse::message(
            'Tugas berhasil dihapus.'
        );
    }

    public function download(
        Request $request,
        Assignment $assignment
    ) {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        if (! $this->canViewAssignment($assignment, $user)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        if ($assignment->file_path === null) {
            return response()->json([
                'message' => 'Tugas ini tidak memiliki file lampiran.',
            ], 404);
        }

        if (! Storage::disk('local')->exists($assignment->file_path)) {
            return response()->json([
                'message' => 'File lampiran tidak ditemukan.',
            ], 404);
        }

        return Storage::disk('local')->download(
            $assignment->file_path,
            $assignment->file_name
        );
    }

    private function applyViewScope(
        Builder $query,
        User $user
    ): void {
        $role = $user->role?->code;

        if (
            in_array(
                $role,
                ['TU', 'KEPALA_SEKOLAH'],
                true
            )
        ) {
            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereHas(
                'jadwalPelajaran',
                fn (Builder $builder) => $builder->where(
                    'guru_id',
                    $guruId
                )
            );

            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereHas(
                'jadwalPelajaran.kelas.anggotaKelas',
                function (Builder $builder) use ($studentId): void {
                    $builder
                        ->where('siswa_id', $studentId)
                        ->whereNull('tanggal_selesai');
                }
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewAssignment(
        Assignment $assignment,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (
            in_array(
                $role,
                ['TU', 'KEPALA_SEKOLAH'],
                true
            )
        ) {
            return true;
        }

        if ($role === 'GURU') {
            return $assignment->jadwalPelajaran
                ->guru_id === $user->guru?->id;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return false;
            }

            return $assignment->jadwalPelajaran
                ->kelas()
                ->whereHas(
                    'anggotaKelas',
                    function (Builder $builder) use ($studentId): void {
                        $builder
                            ->where('siswa_id', $studentId)
                            ->whereNull('tanggal_selesai');
                    }
                )
                ->exists();
        }

        return false;
    }

    private function canManageAssignment(
        Assignment $assignment,
        User $user
    ): bool {
        if ($user->role?->code !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        return $assignment->jadwalPelajaran
            ->guru_id === $guruId;
    }
}