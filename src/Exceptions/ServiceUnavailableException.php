<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class ServiceUnavailableException extends BaseException
{
    public function __construct(
        string $message = 'Service temporarily unavailable.',
        array $data = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 503,
            errorCode: 'SERVICE_UNAVAILABLE',
            data: $data,
            previous: $previous,
        );
    }
}
