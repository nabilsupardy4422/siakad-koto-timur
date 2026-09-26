<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return ApiResponse::success([
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
            'permissions' => Permissions::forRole($user->role->code),
            'assignments' => [],
        ]);
    }
}