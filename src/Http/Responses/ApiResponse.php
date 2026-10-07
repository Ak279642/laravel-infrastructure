<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Responses;

use Ak279642\LaravelInfrastructure\Logging\CorrelationId;
use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = [],
        bool $includeData = true,
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($includeData) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload = array_merge($payload, $meta);
        }

        return self::json($payload, $status, $headers);
    }

    public static function error(
        string $message,
        int $status,
        array $errors = [],
        ?string $errorCode = null,
        array $data = [],
        array $debug = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($data !== []) {
            $payload['data'] = $data;
        }

        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }

        if ($debug !== [] && (bool) config('app.debug')) {
            $payload['debug'] = $debug;
        }

        return self::json($payload, $status, $headers);
    }

    private static function json(
        array $payload,
        int $status,
        array $headers,
    ): JsonResponse {
        $response = response()->json($payload, $status, $headers);
        $correlationId = CorrelationId::current();

        if ($correlationId !== null) {
            $response->headers->set(
                CorrelationId::headerName(),
                $correlationId,
            );
        }

        return $response;
    }
}
