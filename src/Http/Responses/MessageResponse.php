<?php

namespace Ak279642\LaravelInfrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;

class MessageResponse
{
    /**
     * Return success message response.
     */
    public static function make(string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
        ], $status);
    }
}
