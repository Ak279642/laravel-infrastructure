<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Throwable;

final class HttpMethodNotAllowedException extends BaseException
{
    public function __construct(
        string $message = 'Method not allowed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: 405,
            errorCode: 'METHOD_NOT_ALLOWED',
            previous: $previous,
            shouldLog: false,
        );
    }
}
