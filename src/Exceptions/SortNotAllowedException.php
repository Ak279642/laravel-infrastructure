<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class SortNotAllowedException extends InvalidArgumentException
{
    public static function forRepository(string $sort, string $repository, array $allowed): self
    {
        $repository = class_basename($repository);
        $allowedText = $allowed === [] ? 'none' : implode(', ', $allowed);

        return new self("Sort [{$sort}] is not allowed on {$repository}. Allowed sorts: {$allowedText}.");
    }
}
