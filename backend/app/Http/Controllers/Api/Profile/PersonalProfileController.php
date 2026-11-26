<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\TahunAkademik;
use App\Services\AcademicIdentityService;
use App\Support\ApiResponse;
use App\Support\HomeroomReadAccess;
use App\Support\IdentityDirectory;
use App\Support\StudentPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\SchoolPasswordPolicy;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** The authenticated user is the only possible target. No mass assignment. */
final class PersonalProfileController extends Controller
{
    private const ROLES = ['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'];

    public function show(Request $request): JsonResponse
    {
        $this->only($request, []);
        return $this->respond($this->projection(User::query()->findOrFail($request->user()->id)));
    }

    public function update(Request $request): JsonResponse
    {
        $this->only($request, ['profile_version', 'no_telepon', 'alamat']);
        $data = $request->validate([
            'profile_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'no_telepon' => ['sometimes', 'nullable', 'string', 'max:30'],
            'alamat' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        abort_unless(array_key_exists('no_telepon', $data) || array_key_exists('alamat', $data), 422, 'Tidak ada informasi kontak yang dikirim.');
        $result = DB::transaction(function () use ($request, $data) {
            $user = $this->lockedUser($request);
            abort_unless(in_array($user->role?->code, ['GURU', 'SISWA'], true), 403, 'Perubahan kontak akademik hanya tersedia untuk Guru dan Siswa.');
            [$state, $profile] = $this->identity($user, true);
            abort_unless($state === 'linked', 409, 'Profil akademik belum terhubung atau tidak konsisten. Hubungi Tata Usaha.');
            abort_unless(hash_equals(AcademicIdentityService::version($profile), $data['profile_version']), 409, 'Biodata telah berubah. Muat ulang sebelum menyimpan.');
            unset($data['profile_version']);
            $profile->fill($data)->save();
            return $this->projection($user->fresh());
        }, 3);
        return $this->respond($result);
    }

    public function password(Request $request): JsonResponse
    {
        $this->only($request, ['current_password', 'password', 'password_confirmation']);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => SchoolPasswordPolicy::rules(),
        ]);
        DB::transaction(function () use ($request, $data) {
            $user = $this->lockedUser($request);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak sesuai.']);
            }
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari password saat ini.']);
            }
            $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => null])->save();
            $user->tokens()->delete();
        }, 3);
        return ApiResponse::message('Password berhasil diubah. Silakan login kembali.')->header('Cache-Control', 'private, no-store');
    }

    private function lockedUser(Request $request): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($user->account_status === 'active' && ! $user->requires_activation && in_array($user->role?->code, self::ROLES, true), 403);
        // A token revoked while waiting for the user row must not authorize a write.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            abort_unless($user->tokens()->whereKey($token->getKey())->exists(), 401);
        }
        return $user;
    }

    private function only(Request $request, array $fields): void
    {
        $unexpected = array_diff(array_keys($request->all()), $fields);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['profile' => 'Permintaan memuat kolom yang tidak diizinkan.']);
        }
    }

    private function identity(User $user, bool $lock = false): array
    {
        $teacher = $user->guru(); $student = $user->siswa();
        if ($lock) { $teacher->lockForUpdate(); $student->lockForUpdate(); }
        $guru = $teacher->first(); $siswa = $student->first();
        $role = $user->role?->code;
        if ($role === 'GURU' && $guru && ! $siswa) return ['linked', $guru];
        if ($role === 'SISWA' && $siswa && ! $guru) return ['linked', $siswa];
        if ($guru || $siswa) return ['conflict', null];
        return [in_array($role, ['GURU', 'SISWA'], true) ? 'unlinked' : 'not_applicable', null];
    }

    private function projection(User $user): array
    {
        $role = $user->role?->code;
        abort_unless(in_array($role, self::ROLES, true), 403);
        [$state, $profile] = $this->identity($user);
        $fields = IdentityDirectory::fields($role === 'SISWA');
        $biodata = null; $completeness = null;
        if ($profile) {
            $biodata = [];
            foreach ($fields as $field) {
                $biodata[$field] = $field === 'tanggal_lahir' ? $profile->tanggal_lahir?->toDateString() : $profile->{$field};
            }
            $completeness = IdentityDirectory::completeness($profile, $role === 'SISWA');
        }
        $academic = null;
        if (in_array($role, ['GURU', 'SISWA'], true)) {
            $years = TahunAkademik::query()->where('is_active', true)->limit(2)->get();
            $year = $years->count() === 1 ? $years->first() : null;
            $academic = ['period_status' => $year ? 'active' : ($years->isEmpty() ? 'unavailable' : 'conflict'),
                'period' => $year ? ['id' => $year->id, 'tahun_mulai' => $year->tahun_mulai, 'tahun_selesai' => $year->tahun_selesai, 'semester' => $year->semester] : null,
                'placement' => null, 'homeroom_classes' => []];
            if ($year && $profile && $role === 'SISWA') {
                $rows = $profile->anggotaKelas()->whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $year->id))->with('kelas')->get();
                $profile->setRelation('anggotaKelas', $rows);
                $placement = StudentPlacement::summary($profile, $year->id);
                $academic['placement'] = ['status' => $placement['status'], 'class_name' => $placement['status'] === 'effective' ? $placement['class_name'] : null];
            }
            if ($year && $profile && $role === 'GURU') {
                $academic['homeroom_classes'] = HomeroomReadAccess::currentAssignments($profile->id, $year->id)->with('kelas')->get()
                    ->map(fn ($row) => ['id' => $row->kelas_id, 'name' => $row->kelas?->nama])->unique('id')->values()->all();
            }
        }
        return [
            'user' => ['id' => $user->id, 'name' => $profile?->nama_lengkap ?? $user->name, 'username' => $user->username, 'email' => $user->email,
                'role' => ['code' => $role, 'name' => $user->role->name], 'account_status' => $user->account_status,
                'last_login_at' => $user->last_login_at?->toIso8601String(), 'activated_at' => $user->activated_at?->toIso8601String()],
            'profile_status' => $state, 'profile_version' => $profile ? AcademicIdentityService::version($profile) : null,
            'biodata' => $biodata, 'completeness' => $completeness, 'academic' => $academic,
            'can_edit_contact' => $state === 'linked',
        ];
    }

    private function respond(array $data): JsonResponse
    {
        return ApiResponse::success($data)->header('Cache-Control', 'private, no-store');
    }
}
