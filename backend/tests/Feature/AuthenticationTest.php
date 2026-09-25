<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        User::create([
            'role_id' => $role->id,
            'username' => 'tu.test',
            'name' => 'TU Test',
            'email' => 'tu.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'tu.test',
            'password' => 'password-test',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.username', 'tu.test')
            ->assertJsonPath('data.user.name', 'TU Test')
            ->assertJsonPath('data.user.account_status', 'active')
            ->assertJsonPath('data.user.role.code', 'TU')
            ->assertJsonPath('data.user.role.name', 'Tata Usaha');

        $this->assertDatabaseHas('users', [
            'username' => 'tu.test',
            'account_status' => 'active',
        ]);

        $this->assertDatabaseMissing('users', [
            'username' => 'tu.test',
            'last_login_at' => null,
        ]);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        User::create([
            'role_id' => $role->id,
            'username' => 'tu.test',
            'name' => 'TU Test',
            'email' => 'tu.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'tu.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertStatus(401)
            ->assertJson([
                'data' => null,
                'message' => 'Username atau password tidak valid.',
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'tu.test',
            'last_login_at' => null,
        ]);
    }

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->postJson('/api/login', []);

        $response
            ->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'username',
                    'password',
                ],
            ]);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        User::create([
            'role_id' => $role->id,
            'username' => 'inactive.test',
            'name' => 'Inactive Test',
            'email' => 'inactive.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'inactive',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'inactive.test',
            'password' => 'password-test',
        ]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'data' => null,
                'message' => 'Akun tidak aktif.',
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'inactive.test',
            'account_status' => 'inactive',
            'last_login_at' => null,
        ]);
    }
}