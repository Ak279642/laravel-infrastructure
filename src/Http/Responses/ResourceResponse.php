<?php

namespace Ak279642\LaravelInfrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ResourceResponse
{
    public static function make(mixed $resource, string $message = 'Success', int $status = 200): JsonResponse
    {
        if (
            $resource instanceof AnonymousResourceCollection &&
            $resource->resource instanceof LengthAwarePaginator
        ) {
            $paginator = $resource->resource;

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $resource,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resource,
        ], $status);
    }
}
