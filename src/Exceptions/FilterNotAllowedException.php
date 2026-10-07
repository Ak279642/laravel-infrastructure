<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class FilterNotAllowedException extends InvalidArgumentException
{
    /** @param list<string> $allowed */
    public function __construct(string $filter, string $repository, array $allowed)
    {
        parent::__construct(sprintf(
            'Filter [%s] is not allowed on %s. Allowed filters: %s',
            $filter,
            class_basename($repository),
            $allowed === [] ? '(none)' : implode(', ', $allowed),
        ));
    }
}
