<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class FilterNotAllowedException extends InvalidArgumentException
{
    public static function forRepository(string $filter, string $repository, array $allowed): self
    {
        $repository = class_basename($repository);
        $allowedText = $allowed === [] ? 'none' : implode(', ', $allowed);

        return new self("Filter [{$filter}] is not allowed on {$repository}. Allowed filters: {$allowedText}.");
    }
}
