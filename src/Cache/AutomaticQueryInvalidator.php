<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Observes successful SQL writes, including query-builder bulk updates,
 * pivot syncs, insert/upsert and raw DML. No extra database reads.
 *
 * Scope-specific row ownership cannot be inferred from a set-based write, so
 * write-table and explicit cross-table dependencies invalidate conservatively.
 * Dependencies are deduplicated into one after-commit flush per connection.
 */
final class AutomaticQueryInvalidator
{
    /** @var array<int, array{connection: Connection,tags: array<string, true>}> */
    private array $pending = [];

    public function __construct(private readonly CacheInvalidator $invalidator) {}

    public function onQuery(QueryExecuted $event): void
    {
        $tables = SqlCacheDependency::writeTables($event->sql);
        if ($tables === null) {
            return;
        }

        $connection = $event->connection;
        $tags = [];
        $dependencies = config('laravel-infrastructure.cache.auto_invalidation.table_dependencies', []);
        if ($tables === []) {
            $tags[] = SqlCacheDependency::databaseTag($connection);
        }

        foreach ($tables as $table) {
            $tags[] = SqlCacheDependency::tableTag($connection, $table);
            foreach (($dependencies[$table] ?? []) as $modelOrTag) {
                if (! is_string($modelOrTag) || $modelOrTag === '') {
                    continue;
                }
                $tags[] = str_starts_with($modelOrTag, 'model:')
                    ? $modelOrTag
                    : CacheTag::fromModel($modelOrTag);
            }
        }

        $tags = CacheTag::tags(...$tags);
        if ($tags === []) {
            return;
        }

        RequestReadCache::clear();

        if ($connection->transactionLevel() === 0) {
            $this->invalidator->invalidateTags($tags);
            return;
        }

        $id = spl_object_id($connection);
        if (! isset($this->pending[$id])) {
            $this->pending[$id] = ['connection' => $connection, 'tags' => []];
            // Laravel discards afterCommit callbacks on full rollback.
            $connection->afterCommit(function () use ($id): void {
                $entry = $this->pending[$id] ?? null;
                unset($this->pending[$id]);
                if ($entry !== null && $entry['tags'] !== []) {
                    $this->invalidator->invalidateTags(array_keys($entry['tags']));
                }
            });
        }

        foreach ($tags as $tag) {
            $this->pending[$id]['tags'][$tag] = true;
        }
    }

    public function onRollback(TransactionRolledBack $event): void
    {
        if ($event->connection->transactionLevel() === 0) {
            unset($this->pending[spl_object_id($event->connection)]);
        }
        // Partial/savepoint rollbacks deliberately keep a superset of tags.
        // Over-invalidation is safe; missing committed write tags is not.
    }
}
