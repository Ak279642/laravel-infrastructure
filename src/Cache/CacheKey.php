<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Ak279642\LaravelInfrastructure\Exceptions\InvalidCacheConfigurationException;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonSerializable;
use Stringable;
use UnitEnum;

final class CacheKey
{
    private function __construct() {}

    public static function make(string $resource, array $params = []): string
    {
        $resource = self::normalizeResource($resource);
        $params = self::normalize($params);

        return $params === []
            ? $resource
            : $resource.':'.self::hash($params);
    }

    public static function unordered(array|Collection $value): UnorderedArray
    {
        return new UnorderedArray(
            $value instanceof Collection ? $value->all() : $value,
        );
    }

    public static function readable(string $resource, array $params = []): string
    {
        $resource = self::normalizeResource($resource);
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
                        | JSON_THROW_ON_ERROR,
                    )
                    : (string) $value
            );
        }

        return $parts === []
            ? $resource
            : $resource.':'.implode(':', $parts);
    }

    private static function normalizeResource(string $resource): string
    {
        $resource = strtolower(trim($resource, " :\t\n\r\0\x0B"));

        if ($resource === '') {
            throw new InvalidCacheConfigurationException(
                'Cache key resource must not be empty.',
            );
        }

        return $resource;
    }

    private static function hash(array $data): string
    {
        ksort($data);

        return hash(
            'xxh128',
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UnorderedArray) {
            return self::normalizeUnordered($value->values);
        }

        if ($value instanceof Model) {
            return [
                'model' => $value::class,
                'key' => $value->getKey(),
            ];
        }

        if ($value instanceof Collection) {
            return self::normalize($value->all());
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

        if ($value instanceof JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value) || is_resource($value)) {
            $type = is_object($value)
                ? $value::class
                : get_resource_type($value);

            throw new InvalidCacheConfigurationException(
                "Unsupported cache-key parameter type [{$type}].",
            );
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

    private static function normalizeUnordered(array $values): array
    {
        $normalized = self::normalize($values);

        if ($normalized === []) {
            return [];
        }

        $decorated = [];

        foreach ($normalized as $item) {
            $decorated[] = [
                json_encode(
                    $item,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR,
                ),
                $item,
            ];
        }

        usort(
            $decorated,
            static fn (array $a, array $b): int => $a[0] <=> $b[0],
        );

        return array_column($decorated, 1);
    }
}
