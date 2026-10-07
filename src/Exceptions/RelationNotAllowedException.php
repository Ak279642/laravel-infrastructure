<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class RelationNotAllowedException extends InvalidArgumentException
{
    public static function forRepository(string $relation, string $repository, array $allowed): self
    {
        $message = "Relation [{$relation}] is not allowed on {$repository}.";

        if ($allowed !== []) {
            $message .= "\n\nAllowed relations:\n- ".implode("\n- ", $allowed);
        }

        return new self($message);
    }
}
