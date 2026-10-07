<?php

namespace Ak279642\LaravelInfrastructure\Exceptions;

/**
 * Validation Exception (422)
 */
class ValidationException extends BaseException
{
    public function __construct(array $errors = [], string $message = 'Validation failed.')
    {
        parent::__construct(
            message: $message,
            statusCode: 422,
            errors: $errors,
            errorCode: 'VALIDATION_ERROR'
        );
    }
}
