<?php

namespace Ak279642\LaravelInfrastructure\Exceptions;

/**
 * Conflict Exception (409)
 */
class ConflictException extends BaseException
{
    public function __construct(string $message = 'Resource already exists.', array $errors = [])
    {
        parent::__construct(
            message: $message,
            statusCode: 409,
            errors: $errors,
            errorCode: 'CONFLICT'
        );
    }
}
