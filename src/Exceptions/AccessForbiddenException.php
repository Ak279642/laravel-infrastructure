<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class AccessForbiddenException extends BaseException
{
    public function __construct(
        string $message = 'Forbidden.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 403,
            errorCode: 'FORBIDDEN',
            previous: $previous,
        );
    }
}
