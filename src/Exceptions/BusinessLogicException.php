<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use Exception;

final class BusinessLogicException extends BaseException
{
    public function __construct(
        string $message,
        array $errors = [],
        int $statusCode = 422,
        array $data = [],
        ?Exception $previous = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: $statusCode,
            errors: $errors,
            errorCode: 'BUSINESS_LOGIC_ERROR',
            data: $data,
            previous: $previous,
        );
    }
}
