<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class SortNotAllowedException extends InvalidArgumentException
{
    public static function forRepository(string $sort, string $repository, array $allowed): self
    {
        $message = "Sort [{$sort}] is not allowed on {$repository}.";

        if ($allowed !== []) {
            $message .= "\n\nAllowed sorts:\n- ".implode("\n- ", $allowed);
        }

        return new self($message);
    }
}
