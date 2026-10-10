<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

final class CacheTag
{
    /** @var array<string, string> */
    private static array $modelTables = [];

    public static function fromModel(string $modelClass): string
    {
        $tag = 'model:'.strtolower(str_replace('\\', '.', $modelClass));
        if (is_subclass_of($modelClass, Model::class) && ! isset(self::$modelTables[$tag])) {
            // Constructing Eloquent model metadata performs no SELECT queries.
            self::$modelTables[$tag] = (new $modelClass)->getTable();
        }

        return $tag;
    }

    /**
     * Add physical table dependencies to cache READS only. Invalidation calls
     * must retain their exact tags or scoped Eloquent writes would fan out to
     * unrelated owners.
     */
    public static function withReadDependencies(array $tags, Connection $connection): array
    {
        $tags = self::tags(...$tags);
        if (! (bool) config('laravel-infrastructure.auto_invalidation.enabled', false)) {
            return $tags;
        }

        $expanded = $tags;
        $hasTable = false;
        foreach ($tags as $tag) {
            if (isset(self::$modelTables[$tag])) {
                $expanded[] = SqlCacheDependency::tableTag($connection, self::$modelTables[$tag]);
                $hasTable = true;
            }
        }
        if ($hasTable) {
            $expanded[] = SqlCacheDependency::databaseTag($connection);
        }

        return self::tags(...$expanded);
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
