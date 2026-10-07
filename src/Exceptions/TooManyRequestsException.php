<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class TooManyRequestsException extends BaseException
{
    public function __construct(
        string $message = 'Too many requests. Please slow down.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 429,
            errorCode: 'TOO_MANY_REQUESTS',
            previous: $previous,
        );
    }
}
