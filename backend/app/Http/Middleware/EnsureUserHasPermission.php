<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$permissions
    ): Response {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->loadMissing('role');

        if (! $user->role) {
            return response()->json([
                'message' => 'Akun tidak memiliki role.',
            ], 403);
        }

        $userPermissions = Permissions::forRole(
            $user->role->code
        );

        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Anda tidak memiliki permission untuk mengakses resource ini.',
        ], 403);
    }
}