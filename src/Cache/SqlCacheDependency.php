<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;

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
