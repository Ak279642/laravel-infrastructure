<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;

/**
 * Conservative query-shape proof for actor/owner partitions.
 *
 * Only an explicit equality/IN predicate, conjunctively applied to the root
 * physical table in every executed root read, qualifies. Arbitrary OR trees,
 * aliases, CTEs, complex joins, or cross-owner reads fall back to table-wide
 * tags rather than risking stale or leaked data.
 */
final class SqlBoundReadPartitions
{
    private const COLUMNS = [
        'user_id', 'agent_id', 'partner_id', 'owner_id', 'tenant_id',
        'created_by', 'assigned_to', 'assigned_by', 'city_id', 'tier_id',
    ];

    /** @return list<string> */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * @param list<array{sql:string,bindings:array,connection:Connection}> $queries
     * @return list<string>
     */
    public static function tags(array $queries, Connection $connection, string $table): array
    {
        $root = SqlCacheDependency::normalizeTable($table);
        if ($root === '' || $queries === []) {
            return [];
        }

        $tags = [];
        $seenRoot = false;
        $rootPattern = '(?:[\x60"]?'.preg_quote($root, '/').'[\x60"]?)';
        $tableMention = '/\b(?:from|join)\s+'.$rootPattern.'(?=\s|$|[\x60"])/i';
        $qualified = $rootPattern.'\.';

        foreach ($queries as $query) {
            $sql = trim($query['sql']);
            if (! preg_match($tableMention, $sql)) {
                continue;
            }

            $seenRoot = true;
            if ($query['connection'] !== $connection
                || ! preg_match('/^select\b/i', $sql)
                || preg_match_all($tableMention, $sql) !== 1
                || preg_match('/\bor\b/i', $sql)
                || ! preg_match('/\bwhere\b([\s\S]*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|$)/i', $sql, $whereMatch, PREG_OFFSET_CAPTURE)) {
                return [];
            }

            $where = $whereMatch[1][0];
            $whereOffset = $whereMatch[1][1];
            $found = false;

            foreach (self::COLUMNS as $column) {
                $columnPattern = $qualified.'[\x60"]?'.preg_quote($column, '/').'[\x60"]?';
                $pattern = '/'.$columnPattern.'\s*(?:=\s*(\?)|\bin\s*\(((?:\s*\?\s*,)*\s*\?\s*)\))/i';

                if (! preg_match_all($pattern, $where, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $placeholder = $match[1][1] >= 0 ? $match[1] : $match[2];
                    $count = substr_count($placeholder[0], '?');
                    $offset = $whereOffset + $match[0][1] + strpos($match[0][0], $placeholder[0]);
                    $index = substr_count(substr($sql, 0, $offset), '?');

                    for ($i = 0; $i < $count; $i++) {
                        $value = $query['bindings'][$index + $i] ?? null;
                        if (! is_string($value) && ! is_int($value)) {
                            return [];
                        }

                        $tags[] = SqlCacheDependency::rowColumnTag(
                            $connection, $root, $column, $value,
                        );
                        $found = true;
                    }
                }
            }

            if (! $found) {
                return [];
            }
        }

        return $seenRoot ? CacheTag::tags(...$tags) : [];
    }
}
