<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class RelationNotAllowedException extends InvalidArgumentException
{
    public static function forRepository(string $relation, string $repository, array $allowed): self
    {
        $repository = class_basename($repository);
        $allowedText = $allowed === [] ? 'none' : implode(', ', $allowed);

        return new self("Relation [{$relation}] is not allowed on {$repository}. Allowed relations: {$allowedText}.");
    }
}
