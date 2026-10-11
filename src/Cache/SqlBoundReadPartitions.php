<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Connection;

/**
 * Proves ownership partitions only from a restrictive root-table WHERE.
 *
 * Supports conjunctions, parenthesized disjunctions and equality/IN against
 * bound scalar values. Each OR branch MUST contain a provable root-table
 * partition: otherwise the entire read retains broad SQL dependencies.
 *
 * This is deliberately not a general SQL parser. Unknown syntax fails closed
 * to the existing model/table tags.
 */
final class SqlBoundReadPartitions
{
    private const COLUMNS = [
        'user_id', 'admin_id', 'assigned_admin_id', 'created_by_admin_id',
        'agent_id', 'partner_id', 'owner_id', 'tenant_id',
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
        $quotedRoot = '[\x60"]?'.preg_quote($root, '/').'[\x60"]?';
        $tableMention = '/\b(?:from|join)\s+'.$quotedRoot.'(?=\s|$|[\x60"])/i';

        foreach ($queries as $query) {
            $sql = trim($query['sql']);
            if (! preg_match($tableMention, $sql)) {
                continue;
            }

            $seenRoot = true;
            if ($query['connection'] !== $connection
                || ! preg_match('/^select\b/i', $sql)
                || preg_match_all($tableMention, $sql) !== 1
                || ! preg_match('/^select\b[\s\S]*?\bfrom\s+'.$quotedRoot.'(?=\s|$|[\x60"])/i', $sql)
                || preg_match('/\b(?:xor|not|union|intersect|except|case|when|then|else|between|exists|having)\b/i', $sql)
                || str_contains($sql, ';')
                || preg_match('/\bselect\b/i', substr($sql, 6))
                || ! preg_match('/\bwhere\b([\s\S]*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|\boffset\b|$)/i', $sql, $whereMatch, PREG_OFFSET_CAPTURE)) {
                return [];
            }

            $where = $whereMatch[1][0];
            $offset = $whereMatch[1][1];

            // Do not try to parse literal strings, SQL comments, functions,
            // or driver-specific predicate syntaxes as Boolean partitions.
            if (str_contains($where, "'") || str_contains($where, '--')
                || str_contains($where, '/*') || str_contains($where, '*/')) {
                return [];
            }

            $proved = self::prove($where, $offset, $sql, $query['bindings'], $root, $connection);
            if ($proved === null || $proved === []) {
                return [];
            }

            $tags = array_merge($tags, $proved);
        }

        return $seenRoot ? CacheTag::tags(...$tags) : [];
    }

    /**
     * A conjunction needs at least one restrictive partition; a disjunction
     * needs a partition for EACH alternative. This avoids false narrowing of
     * queries such as (user_id = ? OR status = ?).
     *
     * @param list<mixed> $bindings
     * @return list<string>|null
     */
    private static function prove(
        string $expression,
        int $offset,
        string $sql,
        array $bindings,
        string $root,
        Connection $connection,
    ): ?array {
        [$expression, $offset] = self::trimExpression($expression, $offset);

        while (self::wrapped($expression)) {
            $expression = substr($expression, 1, -1);
            $offset++;
            [$expression, $offset] = self::trimExpression($expression, $offset);
        }

        if ($expression === '') {
            return null;
        }

        $orParts = self::split($expression, 'or');
        if (count($orParts) > 1) {
            $tags = [];
            foreach ($orParts as [$part, $partOffset]) {
                $proved = self::prove($part, $offset + $partOffset, $sql, $bindings, $root, $connection);
                if ($proved === null) {
                    return null;
                }
                $tags = array_merge($tags, $proved);
            }

            return CacheTag::tags(...$tags);
        }

        $andParts = self::split($expression, 'and');
        if (count($andParts) > 1) {
            $best = null;
            foreach ($andParts as [$part, $partOffset]) {
                $proved = self::prove($part, $offset + $partOffset, $sql, $bindings, $root, $connection);
                // Actor-bound predicates generally select fewer rows than
                // city/tier filters, even when an actor OR has multiple tags.
                if ($proved !== null && (
                    $best === null
                    || self::priority($proved) < self::priority($best)
                    || (self::priority($proved) === self::priority($best)
                        && count($proved) < count($best))
                )) {
                    $best = $proved;
                }
            }

            return $best;
        }

        // A leaf is recognized ONLY if its entire expression is a single
        // root-qualified equality or IN list of placeholders.
        $identifier = '[\x60"]?'.preg_quote($root, '/').'[\x60"]?\.';
        $columns = implode('|', array_map(static fn (string $c): string => preg_quote($c, '/'), self::COLUMNS));
        $column = '[\x60"]?('.$columns.')[\x60"]?';
        $leaf = '/^'.$identifier.$column.'\s*(?:=\s*(\?)|\bin\s*\(\s*(\?(?:\s*,\s*\?)*)\s*\))$/i';

        if (! preg_match($leaf, $expression, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $placeholders = ($matches[2][1] ?? -1) >= 0 ? $matches[2] : ($matches[3] ?? null);
        if ($placeholders === null || $placeholders[1] < 0) {
            return null;
        }

        $index = substr_count(substr($sql, 0, $offset + $placeholders[1]), '?');
        $count = substr_count($placeholders[0], '?');
        $tags = [];

        for ($i = 0; $i < $count; $i++) {
            if (! array_key_exists($index + $i, $bindings)) {
                return null;
            }

            $value = $bindings[$index + $i];
            if (! is_string($value) && ! is_int($value)) {
                return null;
            }

            $tags[] = SqlCacheDependency::rowColumnTag(
                $connection, $root, strtolower($matches[1][0]), $value,
            );
        }

        return CacheTag::tags(...$tags);
    }

    /** @param list<string> $tags */
    private static function priority(array $tags): int
    {
        // Root-column names are embedded in the hashed tag namespace, not
        // actor values. Lower rank means a narrower typical permission scope.
        foreach ([
            'admin_id', 'assigned_admin_id', 'created_by_admin_id',
            'user_id', 'agent_id', 'partner_id', 'owner_id', 'tenant_id',
            'assigned_to', 'assigned_by', 'created_by',
        ] as $column) {
            foreach ($tags as $tag) {
                if (str_contains($tag, ':column:'.$column.':')) {
                    return 0;
                }
            }
        }

        return 1;
    }

    /** @return array{string,int} */
    private static function trimExpression(string $expression, int $offset): array
    {
        $left = strlen($expression) - strlen(ltrim($expression));
        return [trim($expression), $offset + $left];
    }

    private static function wrapped(string $expression): bool
    {
        if (! str_starts_with($expression, '(') || ! str_ends_with($expression, ')')) {
            return false;
        }

        $depth = 0;
        for ($i = 0, $length = strlen($expression); $i < $length; $i++) {
            $depth += $expression[$i] === '(' ? 1 : ($expression[$i] === ')' ? -1 : 0);
            if ($depth === 0 && $i < $length - 1) {
                return false;
            }
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * Split on a top-level Boolean operator; preserve original byte offsets
     * for binding-position matching. No SQL is executed by this parser.
     *
     * @return list<array{string,int}>
     */
    private static function split(string $expression, string $operator): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $size = strlen($expression);
        $pattern = '/\G\b'.preg_quote($operator, '/').'\b/i';

        for ($i = 0; $i < $size; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;
                if ($depth < 0) {
                    return [[$expression, 0]];
                }
            }

            if ($depth === 0 && preg_match($pattern, $expression, $match, 0, $i)) {
                $parts[] = [substr($expression, $start, $i - $start), $start];
                $i += strlen($match[0]) - 1;
                $start = $i + 1;
            }
        }

        if ($depth !== 0) {
            return [[$expression, 0]];
        }

        $parts[] = [substr($expression, $start), $start];
        return $parts;
    }
}
