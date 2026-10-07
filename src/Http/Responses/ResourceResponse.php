<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class ResourceResponse
{
    public static function make(
        mixed $resource,
        string $message = 'Success',
        int $status = 200,
    ): JsonResponse {
        $data = $resource instanceof JsonResource
            ? $resource->resolve(request())
            : $resource;

        $pagination = self::pagination($resource);

        return ApiResponse::success(
            data: $data,
            message: $message,
            status: $status,
            meta: $pagination === []
                ? []
                : ['pagination' => $pagination],
        );
    }

    private static function pagination(mixed $resource): array
    {
        if (! $resource instanceof AnonymousResourceCollection) {
            return [];
        }

        $paginator = $resource->resource;

        if ($paginator instanceof LengthAwarePaginator) {
            return [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        if ($paginator instanceof CursorPaginator) {
            return [
                'per_page' => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'previous_cursor' => $paginator->previousCursor()?->encode(),
            ];
        }

        if ($paginator instanceof Paginator) {
            return [
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ];
        }

        return [];
    }
}
