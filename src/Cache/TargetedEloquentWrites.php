<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Correlates one Eloquent row's UPDATE/DELETE with its QueryExecuted event.
 *
 * Only a normal model lifecycle whose old/new scoped invalidation tags are
 * available may bypass broad SQL invalidation. Raw SQL, bulk writes, INSERTs,
 * writes with ambiguous key conditions and other models retain broad fallback.
 */
final class TargetedEloquentWrites
{
    /** @var array<int, array{connection: int, table: string, key: string, column: string}> */
    private array $expected = [];

    public function expect(Model $model): void
    {
        if (! $model instanceof CacheableModel
            || ! method_exists($model, 'infrastructureUsesTargetedCacheInvalidation')
            || ! $model->infrastructureUsesTargetedCacheInvalidation()) {
            return;
        }

        $key = $model->getKey();
        $column = $model->getKeyName();
        if ((! is_int($key) && (! is_string($key) || $key === ''))
            || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
            return;
        }

        // If the model cannot produce an affected partition, do not suppress
        // table invalidation. This also handles incomplete composite scopes.
        $modelTag = CacheTag::fromModel($model::class);
        $tags = $model->getCacheInvalidationTags();
        if (array_diff($tags, [$modelTag]) === []) {
            return;
        }

        $this->expected[spl_object_id($model)] = [
            'connection' => spl_object_id($model->getConnection()),
            'table' => SqlCacheDependency::normalizeTable($model->getTable()),
            'key' => (string) $key,
            'column' => $column,
        ];
    }

    public function clear(Model $model): void
    {
        unset($this->expected[spl_object_id($model)]);
    }

    public function consumes(QueryExecuted $event, string $table): bool
    {
        if ($this->expected === []) {
            return false;
        }

        $sql = ltrim($event->sql);
        if (! preg_match('/^(update|delete)\b/i', $sql) || ! str_contains(strtolower($sql), 'where')) {
            return false;
        }

        foreach ($this->expected as $modelId => $expected) {
            if ($expected['connection'] !== spl_object_id($event->connection)
                || $expected['table'] !== $table) {
                continue;
            }

            // Verify the generated single-row Eloquent primary-key predicate;
            // matching the table and key value alone is not enough for bulk SQL.
            $column = preg_quote($expected['column'], '/');
            $identifier = '(?:(?:[\x60"]?[a-zA-Z_][\w$]*[\x60"]?)\.)?[\x60"]?'.$column.'[\x60"]?';
            if (preg_match('/\bwhere\b[\s\S]*'.$identifier.'\s*=\s*\?/i', $sql) !== 1
                || ! in_array($expected['key'], array_map('strval', $event->bindings), true)) {
                continue;
            }

            unset($this->expected[$modelId]);

            return true;
        }

        return false;
    }
}
