<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Legacy template/preview remain available; new identities use the master imports. */
class UserImportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::message(
            'Import akun tanpa profil tidak lagi digunakan. Import Guru atau Siswa melalui Data Master.',
            null,
            422
        );
    }
}
