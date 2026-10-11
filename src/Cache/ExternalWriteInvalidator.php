<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Apply cache invalidations reported by an external database change feed.
 *
 * An external writer cannot fire this application's QueryExecuted listener.
 * A database trigger, CDC consumer or deployment process must deliver a
 * committed table change to this service (or its Artisan command).
 */
final class ExternalWriteInvalidator
{
    public function __construct(private readonly CacheInvalidator $invalidator) {}

    /**
     * Invalidate a committed external change using safe table-wide tags.
     *
     * @param list<string> $tables Physical table names, not model classes.
     */
    public function invalidateTables(array $tables, ?Connection $connection = null): bool
    {
        $connection ??= DB::connection();
        if ($tables === []) {
            throw new InvalidArgumentException('At least one physical table is required.');
        }

        $tags = [SqlCacheDependency::unknownReadTag($connection)];

        foreach ($tables as $table) {
            $table = trim($table);
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid physical table name: '.$table);
            }

            $tags[] = SqlCacheDependency::tableTag($connection, $table);
            $tags[] = SqlCacheDependency::untrackedTableTag($connection, $table);
        }

        RequestReadCache::clear();

        // The external transaction must already be committed before calling.
        return $this->invalidator->invalidateTags(CacheTag::tags(...$tags));
    }
}
