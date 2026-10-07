<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Contracts;

interface CacheableModel
{
    public static function cacheTag(): string;

    /** @return list<string> */
    public function getCacheTags(): array;

    /** @param list<string> $relations @return list<string> */
    public function getCacheDependencyTags(
        mixed $result = null,
        array $relations = []
    ): array;

    /**
     * @return list<string>
     */
    public function getCacheInvalidationTags(): array;

    public function invalidateCache(): void;
}
