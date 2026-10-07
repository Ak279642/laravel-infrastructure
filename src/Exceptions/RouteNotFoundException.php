<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class RouteNotFoundException extends BaseException
{
    public function __construct(
        string $message = 'Not found.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 404,
            errorCode: 'NOT_FOUND',
            previous: $previous,
            shouldLog: false,
        );
    }
}
