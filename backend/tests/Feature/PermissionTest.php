<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([
            'auth:sanctum',
            'permission:grades.view',
        ])->get('/api/test-permission-grades-view', function (
            Request $request
        ) {
            return response()->json([
                'data' => [
                    'message' => 'Permission authorized.',
                ],
            ]);
        });

        Route::middleware([
            'auth:sanctum',
            'permission:grades.create',
        ])->post('/api/test-permission-grades-create', function (
            Request $request
        ) {
            return response()->json([
                'data' => [
                    'message' => 'Permission authorized.',
                ],
            ]);
        });
    }

    public function test_guru_can_access_permission_allowed_to_guru(): void
    {
        $role = Role::create([
            'code' => 'GURU',
            'name' => 'Guru',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'guru.permission.test',
            'name' => 'Guru Permission Test',
            'email' => 'guru.permission.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user
            ->createToken('permission-test')
            ->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/test-permission-grades-view');

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.message',
                'Permission authorized.'
            );
    }

    public function test_guru_can_create_grades(): void
    {
        $role = Role::create([
            'code' => 'GURU',
            'name' => 'Guru',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'guru.create.grade.test',
            'name' => 'Guru Create Grade Test',
            'email' => 'guru.create.grade.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user
            ->createToken('permission-test')
            ->plainTextToken;

        $response = $this
            ->withToken($token)
            ->postJson('/api/test-permission-grades-create');

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.message',
                'Permission authorized.'
            );
    }

    public function test_siswa_cannot_create_grades(): void
    {
        $role = Role::create([
            'code' => 'SISWA',
            'name' => 'Siswa',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'siswa.permission.test',
            'name' => 'Siswa Permission Test',
            'email' => 'siswa.permission.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user
            ->createToken('permission-test')
            ->plainTextToken;

        $response = $this
            ->withToken($token)
            ->postJson('/api/test-permission-grades-create');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Anda tidak memiliki permission untuk mengakses resource ini.',
            ]);
    }

    public function test_tu_can_access_student_management_permission(): void
    {
        Route::middleware([
            'auth:sanctum',
            'permission:students.create',
        ])->post('/api/test-permission-students-create', function () {
            return response()->json([
                'data' => [
                    'message' => 'Permission authorized.',
                ],
            ]);
        });

        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'tu.permission.test',
            'name' => 'TU Permission Test',
            'email' => 'tu.permission.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user
            ->createToken('permission-test')
            ->plainTextToken;

        $response = $this
            ->withToken($token)
            ->postJson('/api/test-permission-students-create');

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.message',
                'Permission authorized.'
            );
    }

    public function test_unauthenticated_user_cannot_pass_permission_middleware(): void
    {
        $response = $this
            ->getJson('/api/test-permission-grades-view');

        $response
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }
}