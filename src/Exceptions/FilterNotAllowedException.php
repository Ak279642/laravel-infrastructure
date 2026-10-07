<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Exceptions;

use InvalidArgumentException;

final class FilterNotAllowedException extends InvalidArgumentException
{
    public static function forRepository(string $filter, string $repository, array $allowed): self
    {
        $message = "Filter [{$filter}] is not allowed on {$repository}.";

        if ($allowed !== []) {
            $message .= "\n\nAllowed filters:\n- ".implode("\n- ", $allowed);
        }

        return new self($message);
    }
}
