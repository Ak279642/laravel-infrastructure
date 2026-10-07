<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class RelationNotAllowedException extends InvalidArgumentException
{
    /** @param list<string> $allowed */
    public function __construct(string $relation, string $repository, array $allowed)
    {
        parent::__construct(sprintf(
            'Relation [%s] is not allowed on %s. Allowed relations: %s',
            $relation,
            class_basename($repository),
            $allowed === [] ? '(none)' : implode(', ', $allowed),
        ));
    }
}
