<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->loadMissing('role');

        if (! $user->role || ! in_array($user->role->code, $roles, true)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return $next($request);
    }
}