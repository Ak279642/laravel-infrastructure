<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
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

    public function render(): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $this->getMessage(),
        ];

        if ($this->errors !== []) {
            $response['errors'] = $this->errors;
        }

        if ($this->data !== []) {
            $response['data'] = $this->data;
        }

        if ($this->errorCode !== null) {
            $response['error_code'] = $this->errorCode;
        }

        if (config('app.debug')) {
            $response['debug'] = [
                'message' => $this->getMessage(),
            ];
        }

        if (
            $this->shouldLog
            && $this->getMessage() !== ''
            && (
                ! app()->bound('request')
                || ! request()->attributes->get(
                    'api_exception_logged',
                    false,
                )
            )
        ) {
            $businessException = $this->statusCode < 500;

            CustomLog::exception(
                $this,
                ['response' => $response],
                $businessException
                    ? 'Business rule violation.'
                    : 'Unexpected server exception.',
                $businessException ? 'warning' : 'error',
                $businessException
                    ? LogDomain::BUSINESS
                    : LogDomain::ERRORS,
            );
        }

        return response()->json($response, $this->statusCode);
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
}
