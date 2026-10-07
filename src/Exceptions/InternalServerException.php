<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class InternalServerException extends BaseException
{
    public function __construct(
        string $message = 'Internal server error.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 500,
            errorCode: 'INTERNAL_ERROR',
            previous: $previous,
        );
    }
}
