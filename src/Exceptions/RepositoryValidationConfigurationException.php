<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use LogicException;

final class RepositoryValidationConfigurationException extends LogicException
{
    public static function invalidRepository(string $repository): self
    {
        return new self(
            "Repository validation requires [{$repository}] to implement ".
            "[Ak279642\\LaravelInfrastructure\\Database\\Repositories\\Contracts\\RepositoryValidationRepository].",
        );
    }

    public static function invalidRule(mixed $rule): self
    {
        return new self(
            'Repository validation rules must be instances of ['.
            'Ak279642\\LaravelInfrastructure\\Validation\\RepositoryValidationRule]; received ['.
            get_debug_type($rule).'].',
        );
    }
}
