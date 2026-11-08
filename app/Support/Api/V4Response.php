<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

final class V4Response
{
    public static function success(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => $meta,
            'error' => null,
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        mixed $details = null,
        array $meta = []
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'meta' => $meta,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
