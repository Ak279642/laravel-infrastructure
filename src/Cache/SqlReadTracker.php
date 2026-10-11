<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Captures physical tables actually read by repository cache callbacks.
 * QueryExecuted fires for joins, subqueries, eager loads and raw SELECTs,
 * so no extra SELECTs or model hydration are needed for dependency discovery.
 *
 * This service is request-scoped, never a process-global static tracker.
 */
final class SqlReadTracker
{
    /** @var list<array{tags: array<string, true>, unsafe: bool}> */
    private array $frames = [];

    public function observe(QueryExecuted $event): void
    {
        if ($this->frames === []) {
            return;
        }

        if (SqlCacheDependency::writeTables($event->sql) !== null) {
            foreach ($this->frames as &$frame) {
                $frame['unsafe'] = true;
            }
            unset($frame);

            return;
        }

        $sql = ltrim($event->sql);
        if (! preg_match('/^(?:select|with|show|explain|pragma|values)\b/i', $sql)) {
            return;
        }

        $tables = SqlCacheDependency::readTables($sql);
        $tags = $tables === []
            ? [SqlCacheDependency::databaseTag($event->connection)]
            : array_map(
                static fn (string $table): string => SqlCacheDependency::tableTag($event->connection, $table),
                $tables,
            );

        $this->dependOn($tags);
    }

    /** @param list<string> $tags */
    public function dependOn(array $tags): void
    {
        if ($this->frames === []) {
            return;
        }

        foreach ($this->frames as &$frame) {
            foreach ($tags as $tag) {
                $frame['tags'][$tag] = true;
            }
        }
        unset($frame);
    }

    /**
     * @return array{value: mixed, tags: list<string>, unsafe: bool}
     */
    public function capture(callable $callback): array
    {
        $this->frames[] = ['tags' => [], 'unsafe' => false];

        try {
            $value = $callback();
        } finally {
            $frame = array_pop($this->frames);
        }

        return [
            'value' => $value,
            'tags' => array_keys($frame['tags']),
            'unsafe' => $frame['unsafe'],
        ];
    }
}
