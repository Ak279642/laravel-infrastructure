<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

final class CacheTag
{
    public static function fromModel(string $modelClass): string
    {
        return 'model:'.strtolower(str_replace('\\', '.', $modelClass));
    }

    public static function entity(string $tag, int|string $id): string
    {
        return trim($tag, ':').':'.$id;
    }

    /** @return list<string> */
    public static function model(string $tag, int|string $id): array
    {
        return self::tags($tag, self::entity($tag, $id));
    }

    /** @return list<string> */
    public static function tags(mixed ...$values): array
    {
        $result = [];

        $walk = static function (mixed $value) use (&$result, &$walk): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }

                return;
            }

            if (! is_scalar($value) && ! $value instanceof \Stringable) {
                return;
            }

            $tag = trim((string) $value);
            if ($tag !== '') {
                $result[$tag] = true;
            }
        };

        foreach ($values as $value) {
            $walk($value);
        }

        return array_keys($result);
    }

    /** @return list<string> */
    public static function merge(array ...$groups): array
    {
        return self::tags(...$groups);
    }
}
