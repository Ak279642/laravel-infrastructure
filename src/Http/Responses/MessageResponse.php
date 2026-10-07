<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;

class MessageResponse
{
    public static function make(
        string $message = 'Success',
        int $status = 200,
    ): JsonResponse {
        return ApiResponse::success(
            message: $message,
            status: $status,
            includeData: false,
        );
    }
}
