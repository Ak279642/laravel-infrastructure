<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Ak279642\LaravelInfrastructure\Http\Responses\ApiResponse;
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Exception;
use Illuminate\Http\JsonResponse;
use Throwable;

class BaseException extends Exception
{
    protected array $errors = [];

    protected int $statusCode;

    protected ?string $errorCode = null;

    protected array $data = [];

    protected bool $shouldLog;

    public function __construct(
        string $message = '',
        int $statusCode = 500,
        array $errors = [],
        ?string $errorCode = null,
        array $data = [],
        ?Throwable $previous = null,
        bool $shouldLog = true,
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->errors = $errors;
        $this->errorCode = $errorCode;
        $this->data = $data;
        $this->shouldLog = $shouldLog;
    }

    /**
     * Prevent Laravel's default logger from logging the same package exception
     * again. The structured package logger runs during render().
     */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        if ($this->shouldWriteLog()) {
            $businessException = $this->statusCode < 500;

            CustomLog::exception(
                $this,
                [
                    'http_status' => $this->statusCode,
                    'error_code' => $this->errorCode,
                ],
                $businessException
                    ? 'API business exception.'
                    : 'API server exception.',
                $businessException ? 'warning' : 'error',
                $businessException
                    ? LogDomain::BUSINESS
                    : LogDomain::ERRORS,
            );

            if (app()->bound('request')) {
                request()->attributes->set(
                    'api_exception_logged',
                    true,
                );
            }
        }

        return ApiResponse::error(
            message: $this->getMessage(),
            status: $this->statusCode,
            errors: $this->errors,
            errorCode: $this->errorCode,
            data: $this->data,
            debug: [
                'exception' => static::class,
                'message' => $this->getMessage(),
                'file' => $this->getFile(),
                'line' => $this->getLine(),
            ],
        );
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getData(): array
    {
        return $this->data;
    }

    private function shouldWriteLog(): bool
    {
        if (
            ! $this->shouldLog
            || $this->getMessage() === ''
            || (
                app()->bound('request')
                && request()->attributes->get(
                    'api_exception_logged',
                    false,
                )
            )
        ) {
            return false;
        }

        return $this->statusCode >= 500
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
