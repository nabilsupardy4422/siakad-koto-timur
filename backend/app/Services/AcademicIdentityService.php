<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Shared identity registration for the TU master-data workflows. */
final class AcademicIdentityService
{
    public function register(string $roleCode, array $data): Model
    {
        [$model, $identifier] = $this->definition($roleCode);
        $mode = $data['registration_mode'] ?? 'existing_account';
        $username = $data['username'] ?? $data[$identifier];
        $accountId = $data['user_id'] ?? null;
        unset($data['registration_mode'], $data['username'], $data['user_id']);

        try {
            return DB::transaction(function () use ($roleCode, $model, $mode, $username, $accountId, $data) {
                if ($mode === 'new_account') {
                    $role = Role::query()->where('code', $roleCode)->firstOrFail();
                    if (User::query()->where('username', $username)->exists()) {
                        throw ValidationException::withMessages(['username' => 'Username sudah digunakan. Pilih username lain atau hubungkan akun yang benar.']);
                    }
                    $user = new User([
                        'role_id' => $role->id,
                        'username' => $username,
                        'name' => $data['nama_lengkap'],
                        'password' => Str::random(48),
                        'account_status' => 'active',
                    ]);
                    $user->requires_activation = true;
                    $user->activated_at = null;
                    $user->save();
                } else {
                    $user = User::query()->with('role')->lockForUpdate()->find($accountId);
                    if (! $user || $user->role?->code !== $roleCode || $user->account_status !== 'active'
                        || $user->guru()->exists() || $user->siswa()->exists()) {
                        throw ValidationException::withMessages(['user_id' => 'Akun tidak tersedia untuk dihubungkan. Periksa role, status dan profil yang sudah ada.']);
                    }
                }
                $profile = $model::create([...$data, 'user_id' => $user->id]);
                $user->update(['name' => $profile->nama_lengkap]);
                return $profile->load('user');
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([$identifier => 'Identitas atau username sudah digunakan. Muat ulang data dan periksa konflik.']);
        }
    }

    public function update(Model $profile, array $data): Model
    {
        try {
            return DB::transaction(function () use ($profile, $data) {
                $user = User::query()->lockForUpdate()->findOrFail($profile->user_id);
                $target = $profile->newQuery()->lockForUpdate()->findOrFail($profile->id);
                $expectedRole = $target instanceof Guru ? 'GURU' : 'SISWA';
                if ($user->role?->code !== $expectedRole || ($target instanceof Guru ? $user->siswa()->exists() : $user->guru()->exists())) {
                    abort(409, 'Relasi akademik tidak konsisten. Periksa akun terkait.');
                }
                if ((int) $target->user_id !== (int) $user->id ||
                    (isset($data['user_id']) && (int) $data['user_id'] !== (int) $target->user_id)) {
                    throw ValidationException::withMessages(['user_id' => 'Relasi akun profil tidak dapat diubah.']);
                }
                if (isset($data['profile_version']) && ! hash_equals(self::version($target), $data['profile_version'])) {
                    abort(409, 'Profil telah berubah. Muat ulang data sebelum menyimpan.');
                }
                unset($data['user_id'], $data['profile_version']);
                $target->update($data);
                $user->update(['name' => $target->nama_lengkap]);
                return $target->load('user');
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $field = $profile instanceof Guru ? 'nip' : 'nisn';
            throw ValidationException::withMessages([$field => 'Identitas sudah digunakan. Periksa konflik data.']);
        }
    }

    public function remove(Model $profile): void
    {
        DB::transaction(function () use ($profile) {
            $user = User::query()->lockForUpdate()->findOrFail($profile->user_id);
            $target = $profile->newQuery()->lockForUpdate()->findOrFail($profile->id);
            $expectedRole = $target instanceof Guru ? 'GURU' : 'SISWA';
            if ($user->role?->code !== $expectedRole || ($target instanceof Guru ? $user->siswa()->exists() : $user->guru()->exists())) {
                abort(409, 'Relasi identitas tidak konsisten. Periksa akun sebelum menghapus profil.');
            }
            $dependencies = $target instanceof Guru
                ? ['waliKelas' => 'riwayat penugasan Wali Kelas', 'jadwalPelajaran' => 'jadwal pelajaran']
                : ['anggotaKelas' => 'riwayat anggota kelas', 'presensi' => 'presensi', 'submissionTugas' => 'submission tugas', 'nilai' => 'nilai akademik', 'rapor' => 'rapor'];
            foreach ($dependencies as $relation => $label) {
                if ($target->{$relation}()->exists()) {
                    $type = $target instanceof Guru ? 'guru' : 'siswa';
                    abort(422, "Data {$type} tidak dapat dihapus karena masih memiliki {$label}.");
                }
            }
            $target->delete();
            $user->update(['account_status' => 'inactive']);
            $user->tokens()->delete();
            DB::table('user_activation_tokens')->where('user_id', $user->id)->whereNull('used_at')->delete();
        }, 3);
    }

    public static function version(Model $profile): string
    {
        $attributes = $profile->getAttributes();
        ksort($attributes);
        return hash_hmac('sha256', json_encode($attributes, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function definition(string $roleCode): array
    {
        return match ($roleCode) {
            'GURU' => [Guru::class, 'nip'],
            'SISWA' => [Siswa::class, 'nisn'],
            default => throw new \InvalidArgumentException('Role akademik tidak valid.'),
        };
    }
}
