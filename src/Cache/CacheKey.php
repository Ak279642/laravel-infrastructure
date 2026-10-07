<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnitEnum;

final class CacheKey
{
    private function __construct() {}

    /**
     * Generate a deterministic cache key.
     */
    public static function make(string $resource, array $params = []): string
    {
        $resource = strtolower(trim($resource, ':'));
        $params = self::normalize($params);

        return empty($params)
            ? $resource
            : $resource.':'.self::hash($params);
    }

    /**
     * Mark an array or collection as unordered.
     */
    public static function unordered(array|Collection $value): UnorderedArray
    {
        return new UnorderedArray(
            $value instanceof Collection ? $value->all() : $value
        );
    }

    /**
     * Generate a human-readable cache key.
     */
    public static function readable(string $resource, array $params = []): string
    {
        $resource = strtolower(trim($resource, ':'));
        $params = self::normalize($params);

        ksort($params);

        $parts = [];

        foreach ($params as $key => $value) {
            $parts[] = $key.'='.(
                is_array($value)
                    ? json_encode(
                        $value,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR
                    )
                    : $value
            );
        }

        return empty($parts)
            ? $resource
            : $resource.':'.implode(':', $parts);
    }

    /**
     * Generate deterministic hash.
     */
    private static function hash(array $data): string
    {
        ksort($data);

        return hash(
            'xxh128',
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * Normalize values recursively.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UnorderedArray) {
            return self::normalizeUnordered($value->values);
        }

        if ($value instanceof Model) {
            return $value->getKey();
        }

        if ($value instanceof Collection) {
            return self::normalize($value->toArray());
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * Normalize and deterministically sort an unordered collection.
     */
    private static function normalizeUnordered(array $values): array
    {
        $normalized = self::normalize($values);

        if ($normalized === []) {
            return [];
        }

        $decorated = [];

        foreach ($normalized as $item) {
            $decorated[] = [
                json_encode($item, JSON_THROW_ON_ERROR),
                $item,
            ];
        }

        usort(
            $decorated,
            static fn (array $a, array $b): int => strcmp($a[0], $b[0])
        );

        return array_column($decorated, 1);
    }
}
