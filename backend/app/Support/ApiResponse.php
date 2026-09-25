<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'data' => $data,
        ], $status);
    }

    public static function message(
        string $message,
        mixed $data = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    public static function collection(
        mixed $data,
        array $meta
    ): JsonResponse {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ]);
    }
}