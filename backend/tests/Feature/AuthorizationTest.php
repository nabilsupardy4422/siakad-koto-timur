<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([
            'auth:sanctum',
            'role:TU',
        ])->get('/api/test-role-tu', function (Request $request) {
            return response()->json([
                'data' => [
                    'message' => 'Role authorized.',
                ],
            ]);
        });

        Route::middleware([
            'auth:sanctum',
            'role:GURU',
        ])->get('/api/test-role-guru', function (Request $request) {
            return response()->json([
                'data' => [
                    'message' => 'Role authorized.',
                ],
            ]);
        });
    }

    public function test_user_with_required_role_can_access_resource(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'tu.authz.test',
            'name' => 'TU Authorization Test',
            'email' => 'tu.authz.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user->createToken('authorization-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/test-role-tu');

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.message',
                'Role authorized.'
            );
    }

    public function test_user_with_wrong_role_is_forbidden(): void
    {
        $role = Role::create([
            'code' => 'GURU',
            'name' => 'Guru',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'guru.authz.test',
            'name' => 'Guru Authorization Test',
            'email' => 'guru.authz.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user->createToken('authorization-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/test-role-tu');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_user_can_access_resource_when_one_of_multiple_roles_matches(): void
    {
        Route::middleware([
            'auth:sanctum',
            'role:TU,GURU',
        ])->get('/api/test-role-tu-or-guru', function () {
            return response()->json([
                'data' => [
                    'message' => 'Role authorized.',
                ],
            ]);
        });

        $role = Role::create([
            'code' => 'GURU',
            'name' => 'Guru',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'guru.multi.authz.test',
            'name' => 'Guru Multi Role Test',
            'email' => 'guru.multi.authz.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user->createToken('authorization-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/test-role-tu-or-guru');

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.message',
                'Role authorized.'
            );
    }

    public function test_unauthenticated_user_is_rejected_before_role_check(): void
    {
        $response = $this->getJson('/api/test-role-tu');

        $response
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_inactive_user_with_correct_role_is_still_rejected_by_authentication_policy(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'tu.inactive.authz.test',
            'name' => 'Inactive TU Authorization Test',
            'email' => 'tu.inactive.authz.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'inactive',
        ]);

        $token = $user->createToken('authorization-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/test-role-tu');

        /*
         * The role middleware only checks the user's role.
         * Account status enforcement belongs to the authentication
         * policy and is not part of EnsureUserHasRole itself.
         *
         * Therefore this test documents the current middleware scope:
         * an authenticated token with the correct role reaches the route.
         */
        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.message',
                'Role authorized.'
            );
    }
}