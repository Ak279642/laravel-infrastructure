<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Ak279642\LaravelInfrastructure\Http\Responses\ApiResponse;
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function shouldRender(Request $request): bool
    {
        return (bool) config(
            'laravel-infrastructure.responses.exception_renderer_enabled',
            true,
        ) && $request->expectsJson();
    }

    public function render(
        Throwable $exception,
        Request $request,
    ): JsonResponse {
        $mapped = $this->map($exception);

        if ($this->shouldLog($mapped['status'])) {
            CustomLog::exception(
                $exception,
                [
                    'http_status' => $mapped['status'],
                    'error_code' => $mapped['error_code'],
                ],
                $mapped['status'] >= 500
                    ? 'Unhandled API exception.'
                    : 'API request rejected.',
                $mapped['status'] >= 500 ? 'error' : 'warning',
                $mapped['status'] >= 500
                    ? LogDomain::ERRORS
                    : LogDomain::API,
            );

            $request->attributes->set('api_exception_logged', true);
        }

        return ApiResponse::error(
            message: $mapped['message'],
            status: $mapped['status'],
            errors: $mapped['errors'],
            errorCode: $mapped['error_code'],
            debug: $mapped['debug'],
            headers: $mapped['headers'],
        );
    }

    /**
     * @return array{
     *     status:int,
     *     message:string,
     *     error_code:string,
     *     errors:array,
     *     debug:array,
     *     headers:array
     * }
     */
    private function map(Throwable $exception): array
    {
        if ($exception instanceof BaseException) {
            return [
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
                'error_code' => $exception->getErrorCode()
                    ?? $this->errorCodeForStatus($exception->getStatusCode()),
                'errors' => $exception->getErrors(),
                'debug' => $this->debug($exception),
                'headers' => [],
            ];
        }

        if ($exception instanceof LaravelValidationException) {
            return [
                'status' => $exception->status,
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Validation failed.',
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $exception->errors(),
                'debug' => $this->debug($exception),
                'headers' => [],
            ];
        }

        if ($exception instanceof AuthenticationException) {
            return $this->simple(
                401,
                'Unauthenticated.',
                'UNAUTHORIZED',
                $exception,
            );
        }

        if (
            $exception instanceof AuthorizationException
            || $exception instanceof AccessDeniedHttpException
        ) {
            return $this->simple(
                403,
                'Forbidden.',
                'FORBIDDEN',
                $exception,
            );
        }

        if (
            $exception instanceof ModelNotFoundException
            || $exception instanceof NotFoundHttpException
        ) {
            return $this->simple(
                404,
                'Resource not found.',
                'NOT_FOUND',
                $exception,
                $exception instanceof HttpExceptionInterface
                    ? $exception->getHeaders()
                    : [],
            );
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            return $this->simple(
                405,
                'Method not allowed.',
                'METHOD_NOT_ALLOWED',
                $exception,
                $exception->getHeaders(),
            );
        }

        if ($exception instanceof TooManyRequestsHttpException) {
            return $this->simple(
                429,
                'Too many requests. Please slow down.',
                'TOO_MANY_REQUESTS',
                $exception,
                $exception->getHeaders(),
            );
        }

        if (
            $exception instanceof FilterNotAllowedException
            || $exception instanceof SortNotAllowedException
            || $exception instanceof RelationNotAllowedException
        ) {
            return $this->simple(
                400,
                $exception->getMessage(),
                'INVALID_QUERY',
                $exception,
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return $this->simple(
                $status,
                $this->messageForStatus($status, $exception),
                $this->errorCodeForStatus($status),
                $exception,
                $exception->getHeaders(),
            );
        }

        return $this->simple(
            500,
            'Internal server error.',
            'INTERNAL_ERROR',
            $exception,
        );
    }

    /**
     * @return array{
     *     status:int,
     *     message:string,
     *     error_code:string,
     *     errors:array,
     *     debug:array,
     *     headers:array
     * }
     */
    private function simple(
        int $status,
        string $message,
        string $errorCode,
        Throwable $exception,
        array $headers = [],
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => [],
            'debug' => $this->debug($exception),
            'headers' => $headers,
        ];
    }

    private function messageForStatus(
        int $status,
        Throwable $exception,
    ): string {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            409 => 'Conflict.',
            419 => 'Page expired.',
            422 => 'Validation failed.',
            429 => 'Too many requests. Please slow down.',
            503 => 'Service temporarily unavailable.',
            default => $status >= 500
                ? 'Internal server error.'
                : ($exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Request could not be completed.'),
        };
    }

    private function errorCodeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            419 => 'PAGE_EXPIRED',
            422 => 'VALIDATION_ERROR',
            429 => 'TOO_MANY_REQUESTS',
            503 => 'SERVICE_UNAVAILABLE',
            default => $status >= 500
                ? 'INTERNAL_ERROR'
                : 'HTTP_ERROR',
        };
    }

    private function debug(Throwable $exception): array
    {
        if (! (bool) config('app.debug')) {
            return [];
        }

        return [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    private function shouldLog(int $status): bool
    {
        return $status >= 500
            ? (bool) config(
                'laravel-infrastructure.logging.log_server_exceptions',
                true,
            )
            : (bool) config(
                'laravel-infrastructure.logging.log_client_exceptions',
                false,
            );
    }
}
