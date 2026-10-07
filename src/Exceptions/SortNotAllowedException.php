<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class SortNotAllowedException extends InvalidArgumentException
{
    /** @param list<string> $allowed */
    public function __construct(string $sort, string $repository, array $allowed)
    {
        parent::__construct(sprintf(
            'Sort [%s] is not allowed on %s. Allowed sorts: %s',
            $sort,
            class_basename($repository),
            $allowed === [] ? '(none)' : implode(', ', $allowed),
        ));
    }
}
