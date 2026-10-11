<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/** Compile SQL dependency tags without executing any SQL. */
final class SqlCacheDependency
{
    public static function databaseTag(Connection $connection): string
    {
        return 'infra:db:'.hash('xxh128', implode('|', [
            (string) $connection->getName(),
            (string) $connection->getDatabaseName(),
        ]));
    }

    /**
     * Only opaque/unparseable read statements use this dependency.
     * Every database write must invalidate it, without flushing all normal
     * table-specific repository cache entries.
     */
    /**
     * Targeted owner partitions still depend on writes that do not dispatch
     * reliable per-row Eloquent lifecycle events (bulk/raw/unknown writes).
     * A verified single-row Eloquent event does not invalidate this tag.
     */
    /**
     * Hashed physical-table value partition. Used by proven restrictive SQL
     * reads and Eloquent's before/after row attribute snapshots.
     */
    public static function rowColumnTag(
        Connection $connection,
        string $table,
        string $column,
        string|int $value,
    ): string {
        return self::tableTag($connection, $table)
            .':column:'.strtolower($column).':'.hash('sha256', (string) $value);
    }

    /** @return list<string> */
    public static function affectedRowTags(Model $model): array
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $attributes = $model->getAttributes();
        $original = $model->getRawOriginal();
        $tags = [];

        foreach (SqlBoundReadPartitions::columns() as $column) {
            foreach ([$attributes[$column] ?? null, $original[$column] ?? null] as $value) {
                if (is_string($value) || is_int($value)) {
                    $tags[] = self::rowColumnTag($connection, $table, $column, $value);
                }
            }
        }

        return CacheTag::tags(...$tags);
    }

    public static function untrackedTableTag(Connection $connection, string $table): string
    {
        return self::tableTag($connection, $table).':untracked-write';
    }

    public static function unknownReadTag(Connection $connection): string
    {
        return self::databaseTag($connection).':unknown-read';
    }

    public static function tableTag(Connection $connection, string $table): string
    {
        return self::databaseTag($connection).':table:'.self::normalizeTable($table);
    }

    public static function normalizeTable(string $table): string
    {
        $table = trim($table, " \t\r\n\x60\"[]");
        $parts = explode('.', $table);
        return strtolower(trim((string) end($parts), " \t\r\n\x60\"[]"));
    }

    /** @return list<string> */
    public static function readTables(string $sql): array
    {
        preg_match_all(
            '/\b(?:from|join)\s+((?:[\x60"][a-zA-Z_][\w$]*[\x60"]|[a-zA-Z_][\w$]*)(?:\.(?:[\x60"][a-zA-Z_][\w$]*[\x60"]|[a-zA-Z_][\w$]*))?)/i',
            $sql,
            $matches,
        );

        return array_values(array_unique(array_filter(array_map(
            self::normalizeTable(...),
            $matches[1] ?? [],
        ))));
    }

    /**
     * null = read/not tracked; [] = unknown write target (broad fallback).
     * @return list<string>|null
     */
    public static function writeTables(string $sql): ?array
    {
        $sql = preg_replace('#^\s*(?:(?:/\*.*?\*/|--[^\r\n]*\r?\n)\s*)*#s', '', $sql) ?? $sql;
        $sql = ltrim($sql);
        if (! preg_match('/^(insert|replace|update|delete|truncate|alter|create|drop|rename|merge|with)\b/i', $sql)) {
            return null;
        }
        // Common-table-expression SELECT statements are reads, not writes.
        if (preg_match('/^with\b/i', $sql)
            && ! preg_match('/\b(?:insert\s+into|update\s+\S+\s+set|delete\s+from|replace\s+into)\b/i', $sql)) {
            return null;
        }

        $id = '((?:[\x60"][a-zA-Z_][\w$]*[\x60"]|[a-zA-Z_][\w$]*)(?:\.(?:[\x60"][a-zA-Z_][\w$]*[\x60"]|[a-zA-Z_][\w$]*))?)';
        $patterns = [
            '/^insert\s+(?:ignore\s+)?into\s+'.$id.'/i',
            '/^replace\s+into\s+'.$id.'/i',
            '/^update\s+'.$id.'\s+(?:as\s+\w+\s+)?set\b/i',
            '/^delete\s+from\s+'.$id.'/i',
            '/^truncate\s+(?:table\s+)?'.$id.'/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $match)) {
                $table = self::normalizeTable($match[1]);
                return $table !== '' ? [$table] : [];
            }
        }

        return [];
    }
}
