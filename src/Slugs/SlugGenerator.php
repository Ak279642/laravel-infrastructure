<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Slugs;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SlugGenerator
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly SchemaRegistry $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function generateFor(Model $model, array $options = []): ?string
    {
        $options = array_replace(
            [
                'enabled' => false,
                'source' => 'name',
                'column' => 'slug',
                'unique' => true,
                'regenerate_on_update' => false,
                'separator' => '-',
                'scope' => [],
            ],
            $options,
        );

        if (! (bool) ($options['enabled'] ?? false)) {
            return null;
        }

        $column = (string) ($options['column'] ?? 'slug');

        if (! $this->schema->has(
            $model->getConnection(),
            $model->getTable(),
            $column,
        )) {
            return null;
        }

        $sourceValue = $this->resolveSourceValue(
            $model,
            $options['source'] ?? 'name',
        );

        if ($sourceValue === null) {
            return null;
        }

        $separator = (string) ($options['separator'] ?? '-');
        $baseSlug = Str::slug($sourceValue, $separator);

        if ($baseSlug === '') {
            return null;
        }

        if (! (bool) ($options['unique'] ?? true)) {
            return $baseSlug;
        }

        $where = $this->resolveScope(
            $model,
            (array) ($options['scope'] ?? []),
        );

        $repository = new SlugLookupRepository(
            $model->newInstance(),
            $this->cache,
        );

        $slugs = $repository->matching(
            column: $column,
            baseSlug: $baseSlug,
            where: $where,
            ignoreId: $model->exists ? $model->getKey() : null,
        )->map(static fn ($slug): string => (string) $slug);

        if (! $slugs->contains($baseSlug)) {
            return $baseSlug;
        }

        $maxSuffix = 0;

        foreach ($slugs as $slug) {
            if (preg_match(
                '/^'.preg_quote($baseSlug, '/').preg_quote($separator, '/').'(\d+)$/',
                $slug,
                $matches,
            )) {
                $maxSuffix = max($maxSuffix, (int) $matches[1]);
            }
        }

        return $baseSlug.$separator.($maxSuffix + 1);
    }

    private function resolveSourceValue(
        Model $model,
        string|array $source,
    ): ?string {
        $sources = is_array($source) ? $source : [$source];

        foreach ($sources as $column) {
            if (! is_string($column) || $column === '') {
                continue;
            }

            if (! $this->schema->has(
                $model->getConnection(),
                $model->getTable(),
                $column,
            )) {
                continue;
            }

            $value = $model->getAttribute($column);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function resolveScope(Model $model, array $scope): array
    {
        $where = [];

        foreach ($scope as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $where[$value] = $model->getAttribute($value);

                continue;
            }

            if (is_string($key)) {
                $where[$key] = $value;
            }
        }

        return $where;
    }
}
