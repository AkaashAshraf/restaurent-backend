<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Standard response envelope (spec #123):
 *   success: {"success": true, "data": {...}}
 *   error:   {"success": false, "code": "FEATURE_DISABLED", "message": "..."}
 */
class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];

        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $extra), $status);
    }
}
