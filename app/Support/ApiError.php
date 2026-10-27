<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiError
{
    public static function make(string $code, string $message, int $status, ?array $details = null, array $headers = []): JsonResponse
    {
        $body = ['error' => array_filter([
            'code' => $code,
            'message' => $message,
            'request_id' => request()->attributes->get('request_id'),
            'details' => $details,
        ], fn ($v) => $v !== null)];

        return response()->json($body, $status, $headers);
    }
}
