<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

class NotFoundException extends BaseException
{
    public function __construct(
        string $message = 'Resource not found.',
        array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 404,
            errors: $errors,
            errorCode: 'NOT_FOUND',
            previous: $previous,
        );
    }
}
