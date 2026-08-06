<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class MobileApiResponse
{
    public const SCHEMA_VERSION = 1;

    public const MINIMUM_CLIENT_VERSION = '1.0.0';

    public static function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => self::meta(),
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
            'meta' => self::meta(),
        ], $status);
    }

    public static function meta(): array
    {
        return [
            'server_time' => now()->toIso8601String(),
            'schema_version' => self::SCHEMA_VERSION,
            'minimum_client_version' => self::MINIMUM_CLIENT_VERSION,
        ];
    }
}
