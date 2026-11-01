<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code, string $name): Role
    {
        return Role::create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function createUser(string $roleCode): User
    {
        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            $role = $this->createRole(
                $roleCode,
                match ($roleCode) {
                    'TU' => 'Tata Usaha',
                    'KEPALA_SEKOLAH' => 'Kepala Sekolah',
                    'GURU' => 'Guru',
                    'SISWA' => 'Siswa',
                    default => $roleCode,
                }
            );
        }

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . '_' . uniqid(),
            'name' => 'User ' . $roleCode,
            'email' => strtolower($roleCode) . '_' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function validUserPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'akun_baru',
            'name' => 'Pengguna Baru',
            'email' => 'pengguna.baru@example.test',
            'role_id' => Role::where('code', 'GURU')->firstOrFail()->id,
            'password' => 'PasswordTest123!',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'TU' => 'Tata Usaha',
            'KEPALA_SEKOLAH' => 'Kepala Sekolah',
            'GURU' => 'Guru',
            'SISWA' => 'Siswa',
        ] as $code => $name) {
            if (! Role::where('code', $code)->exists()) {
                $this->createRole($code, $name);
            }
        }
    }

    public function test_guest_cannot_access_user_list(): void
    {
        $this->getJson('/api/users')
            ->assertUnauthorized();
    }

    public function test_tu_can_view_user_list_and_search(): void
    {
        $tu = $this->createUser('TU');

        $target = User::create([
            'role_id' => Role::where('code', 'GURU')->firstOrFail()->id,
            'username' => 'guru_rayhan',
            'name' => 'Rayhan Saputra',
            'email' => 'rayhan@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);

        $this->createUser('SISWA');

        Sanctum::actingAs($tu);

        $response = $this->getJson('/api/users?search=Rayhan');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.username', 'guru_rayhan')
            ->assertJsonStructure([
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    public function test_tu_can_create_user(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $response = $this->postJson(
            '/api/users',
            $this->validUserPayload()
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.username', 'akun_baru')
            ->assertJsonPath('data.name', 'Pengguna Baru')
            ->assertJsonPath('data.account_status', 'active')
            ->assertJsonPath('data.google_linked', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.google_id');

        $user = User::where('username', 'akun_baru')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'akun_baru',
            'account_status' => 'active',
        ]);

        $this->assertTrue(
            Hash::check('PasswordTest123!', $user->password)
        );
    }

    public function test_create_user_requires_valid_fields(): void
    {
        Sanctum::actingAs($this->createUser('TU'));

        $this->postJson('/api/users', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'username',
                'name',
                'role_id',
                'password',
            ]);
    }

    public function test_create_user_rejects_duplicate_username_and_email(): void
    {
        Sanctum::actingAs($this->createUser('TU'));

        User::create([
            'role_id' => Role::where('code', 'GURU')->firstOrFail()->id,
            'username' => 'username_duplikat',
            'name' => 'Pengguna Lama',
            'email' => 'duplikat@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);

        $this->postJson('/api/users', $this->validUserPayload([
            'username' => 'username_duplikat',
            'email' => 'duplikat@example.test',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email']);
    }

    public function test_tu_can_update_user(): void
    {
        $tu = $this->createUser('TU');
        $target = $this->createUser('GURU');

        Sanctum::actingAs($tu);

        $this->putJson("/api/users/{$target->id}", [
            'name' => 'Nama Diperbarui',
            'email' => 'email.baru@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Diperbarui')
            ->assertJsonPath('data.email', 'email.baru@example.test')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.google_id');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Nama Diperbarui',
            'email' => 'email.baru@example.test',
        ]);
    }

    public function test_tu_can_activate_and_deactivate_user(): void
    {
        $tu = $this->createUser('TU');
        $target = $this->createUser('GURU');

        $target->update(['account_status' => 'inactive']);

        Sanctum::actingAs($tu);

        $this->postJson("/api/users/{$target->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'active');

        $this->postJson("/api/users/{$target->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'inactive');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'account_status' => 'inactive',
        ]);
    }

    public function test_non_tu_cannot_manage_users(): void
    {
        $guru = $this->createUser('GURU');
        $target = $this->createUser('SISWA');

        Sanctum::actingAs($guru);

        $this->getJson('/api/users')->assertForbidden();

        $this->postJson('/api/users', $this->validUserPayload([
            'username' => 'ditolak',
            'email' => 'ditolak@example.test',
        ]))->assertForbidden();

        $this->putJson("/api/users/{$target->id}", [
            'name' => 'Perubahan Tidak Sah',
        ])->assertForbidden();

        $this->postJson("/api/users/{$target->id}/activate")
            ->assertForbidden();

        $this->postJson("/api/users/{$target->id}/deactivate")
            ->assertForbidden();
    }

    public function test_user_response_never_exposes_password_or_google_id(): void
    {
        $tu = $this->createUser('TU');
        $target = $this->createUser('GURU');

        Sanctum::actingAs($tu);

        $this->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.google_id')
            ->assertJsonPath('data.google_linked', false);
    }

    public function test_user_detail_returns_404_when_not_found(): void
    {
        Sanctum::actingAs($this->createUser('TU'));

        $this->getJson('/api/users/999999')
            ->assertNotFound();
    }


    public function test_tu_cannot_deactivate_own_account(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->postJson("/api/users/{$tu->id}/deactivate")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Anda tidak dapat menonaktifkan akun sendiri.'
            );

        $this->assertDatabaseHas('users', [
            'id' => $tu->id,
            'account_status' => 'active',
        ]);
    }



    public function test_last_active_tu_account_cannot_be_deactivated(): void
    {
        $tu = $this->createUser('TU');
        $otherTu = $this->createUser('TU');
        $guru = $this->createUser('GURU');

        // Sisakan satu akun TU aktif.
        $otherTu->update(['account_status' => 'inactive']);

        // Gunakan akun TU aktif sebagai pemanggil.
        Sanctum::actingAs($tu);

        // Target harus akun TU aktif yang berbeda dari pemanggil.
        // Dengan demikian, test ini menguji aturan TU aktif terakhir,
        // bukan aturan larangan menonaktifkan diri sendiri.
        $this->postJson("/api/users/{$tu->id}/deactivate")
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $tu->id,
            'account_status' => 'active',
        ]);
    }


    public function test_tu_can_change_role_for_user_without_academic_profile(): void
    {
        $tu = $this->createUser('TU');
        $target = $this->createUser('GURU');

        Sanctum::actingAs($tu);

        $siswaRoleId = Role::where('code', 'SISWA')
            ->firstOrFail()
            ->id;

        $this->putJson("/api/users/{$target->id}", [
            'role_id' => $siswaRoleId,
        ])
            ->assertOk()
            ->assertJsonPath('data.role.code', 'SISWA');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role_id' => $siswaRoleId,
        ]);

        $this->assertDatabaseMissing('guru', [
            'user_id' => $target->id,
        ]);

        $this->assertDatabaseMissing('siswa', [
            'user_id' => $target->id,
        ]);
    }

    public function test_user_with_guru_profile_cannot_change_to_another_role(): void
    {
        $tu = $this->createUser('TU');
        $target = $this->createUser('GURU');

        $originalRoleId = $target->role_id;

        DB::table('guru')->insert([
            'user_id' => $target->id,
            'nip' => 'TEST-GURU-' . $target->id,
            'nama_lengkap' => $target->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($tu);

        $siswaRoleId = Role::where('code', 'SISWA')
            ->firstOrFail()
            ->id;

        $this->putJson("/api/users/{$target->id}", [
            'role_id' => $siswaRoleId,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Role akun tidak dapat diubah karena masih terhubung dengan profil Guru. Selesaikan perubahan identitas akademik melalui prosedur yang sesuai terlebih dahulu.'
            );

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role_id' => $originalRoleId,
        ]);

        $this->assertDatabaseHas('guru', [
            'user_id' => $target->id,
            'nip' => 'TEST-GURU-' . $target->id,
        ]);

        $this->assertDatabaseMissing('siswa', [
            'user_id' => $target->id,
        ]);
    }

    public function test_user_with_siswa_profile_cannot_change_to_another_role(): void
    {
        $tu = $this->createUser('TU');
        $target = $this->createUser('SISWA');

        $originalRoleId = $target->role_id;

        DB::table('siswa')->insert([
            'user_id' => $target->id,
            'nisn' => 'TEST-SISWA-' . $target->id,
            'nama_lengkap' => $target->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($tu);

        $guruRoleId = Role::where('code', 'GURU')
            ->firstOrFail()
            ->id;

        $this->putJson("/api/users/{$target->id}", [
            'role_id' => $guruRoleId,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Role akun tidak dapat diubah karena masih terhubung dengan profil Siswa. Selesaikan perubahan identitas akademik melalui prosedur yang sesuai terlebih dahulu.'
            );

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role_id' => $originalRoleId,
        ]);

        $this->assertDatabaseHas('siswa', [
            'user_id' => $target->id,
            'nisn' => 'TEST-SISWA-' . $target->id,
        ]);

        $this->assertDatabaseMissing('guru', [
            'user_id' => $target->id,
        ]);
    }

}
