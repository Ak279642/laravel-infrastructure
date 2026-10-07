<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class UnauthorizedException extends BaseException
{
    public function __construct(
        string $message = 'Unauthenticated. Please login again.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 401,
            errorCode: 'UNAUTHORIZED',
            previous: $previous,
        );
    }
}
