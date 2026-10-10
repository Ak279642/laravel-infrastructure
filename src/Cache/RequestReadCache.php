<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

/**
 * Request-scoped read memoization for repositories that deliberately avoid
 * persistent caching (for example, permission-sensitive API responses).
 *
 * Request attributes, rather than static state, keep Octane requests isolated.
 * A write must clear this memo immediately, even inside a DB transaction.
 */
final class RequestReadCache
{
    private const ATTRIBUTE = 'laravel-infrastructure.repository.request-reads';

    public static function remember(string $key, callable $callback): mixed
    {
        if (! app()->bound('request')) {
            return $callback();
        }

        $attributes = request()->attributes;
        $values = $attributes->get(self::ATTRIBUTE, []);
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        $value = $callback();
        $values = $attributes->get(self::ATTRIBUTE, []);
        $values[$key] = $value;
        $attributes->set(self::ATTRIBUTE, $values);

        return $value;
    }

    public static function clear(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::ATTRIBUTE);
        }
    }
}
