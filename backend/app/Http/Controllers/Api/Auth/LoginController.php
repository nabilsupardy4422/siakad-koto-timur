<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::with('role')
            ->where('username', $credentials['username'])
            ->first();

        if (
            ! $user ||
            ! Hash::check($credentials['password'], $user->password)
        ) {
            return ApiResponse::message(
                'Username atau password tidak valid.',
                null,
                401
            );
        }

        if ($user->account_status !== 'active') {
            return ApiResponse::message(
                'Akun tidak aktif.',
                null,
                403
            );
        }

        $user->update([
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('siakad-api')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'account_status' => $user->account_status,
                'role' => [
                    'code' => $user->role->code,
                    'name' => $user->role->name,
                ],
            ],
        ]);
    }
}