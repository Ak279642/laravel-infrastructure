<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class HttpRequestException extends BaseException
{
    public function __construct(
        string $message = 'Request could not be completed.',
        int $statusCode = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: $statusCode,
            errorCode: 'HTTP_ERROR',
            previous: $previous,
        );
    }
}
