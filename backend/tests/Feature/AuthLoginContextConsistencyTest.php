<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginContextConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guru_profile_is_immediately_available_after_password_login_and_matches_me(): void
    {
        $role = Role::create(['code' => 'GURU', 'name' => 'Guru']);
        $user = User::create([
            'role_id' => $role->id,
            'username' => 'ujiguru.login',
            'name' => 'Guru Uji Login',
            'email' => 'ujiguru.login@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);
        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => '198001012026019998',
            'nama_lengkap' => 'Guru Uji Login',
        ]);

        $login = $this->postJson('/api/login', [
            'username' => 'ujiguru.login',
            'password' => 'password-test',
        ]);
        $login->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role.code', 'GURU')
            ->assertJsonPath('data.user.guru.id', $guru->id)
            ->assertJsonPath('data.user.guru.nip', $guru->nip)
            ->assertJsonPath('data.user.guru.nama_lengkap', $guru->nama_lengkap);

        $me = $this->withToken($login->json('data.token'))->getJson('/api/me');
        $me->assertOk()
            ->assertJsonPath('data.user.guru', $login->json('data.user.guru'));
    }

    public function test_guru_account_without_linked_profile_does_not_fabricate_teacher_identity(): void
    {
        $role = Role::create(['code' => 'GURU', 'name' => 'Guru']);
        User::create([
            'role_id' => $role->id,
            'username' => 'guru.no-profile',
            'name' => 'Guru Belum Ditautkan',
            'email' => 'guru.no-profile@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'username' => 'guru.no-profile',
            'password' => 'password-test',
        ])->assertOk()
            ->assertJsonPath('data.user.role.code', 'GURU')
            ->assertJsonPath('data.user.guru', null);
    }

    public function test_tu_login_has_no_teacher_identity_and_keeps_its_own_role(): void
    {
        $role = Role::create(['code' => 'TU', 'name' => 'Tata Usaha']);
        User::create([
            'role_id' => $role->id,
            'username' => 'tu.login',
            'name' => 'TU Login',
            'email' => 'tu.login@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'username' => 'tu.login',
            'password' => 'password-test',
        ])->assertOk()
            ->assertJsonPath('data.user.role.code', 'TU')
            ->assertJsonPath('data.user.guru', null);
    }
}
