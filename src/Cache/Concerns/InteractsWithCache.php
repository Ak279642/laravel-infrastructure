<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Throwable;

trait InteractsWithCache
{
    public static function bootInteractsWithCache(): void
    {
        static::created(CacheObserver::class.'@created');
        static::updated(CacheObserver::class.'@updated');
        static::deleted(CacheObserver::class.'@deleted');

        if (
            in_array(
                SoftDeletes::class,
                class_uses_recursive(static::class),
                true,
            )
        ) {
            static::restored(CacheObserver::class.'@restored');
            static::forceDeleted(CacheObserver::class.'@forceDeleted');
        }
    }

    public static function cacheTag(): string
    {
        return CacheTag::fromModel(static::class);
    }

    public function getCacheTags(): array
    {
        $id = $this->getKey();

        return $id === null
            ? [static::cacheTag()]
            : CacheTag::model(
                static::cacheTag(),
                $id,
            );
    }

    public function getCacheDependencyTags(
        mixed $result = null,
        array $relations = [],
    ): array {
        $tags = [static::cacheTag()];

        if ($result instanceof CacheableModel) {
            $tags = CacheTag::merge(
                $tags,
                $result->getCacheTags(),
            );
        }

        foreach ($relations as $relation) {
            if (
                ! is_string($relation)
                || trim($relation) === ''
            ) {
                continue;
            }

            $tags = CacheTag::merge(
                $tags,
                $this->resolveInfrastructureRelationTags(
                    $this,
                    trim(
                        explode(
                            ':',
                            $relation,
                            2,
                        )[0],
                    ),
                ),
            );
        }

        return CacheTag::tags(...$tags);
    }

    public function getCacheInvalidationTags(): array
    {
        return CacheTag::merge(
            [static::cacheTag()],
            $this->getCacheTags(),
        );
    }

    public function invalidateCache(): void {}

    /**
     * Resolve both class-level and loaded entity-level dependency tags for a
     * nested relation path such as orders.items.product.
     *
     * @return list<string>
     */
    private function resolveInfrastructureRelationTags(
        Model $model,
        string $path,
    ): array {
        if ($path === '') {
            return [];
        }

        [$segment, $remaining] = array_pad(
            explode('.', $path, 2),
            2,
            null,
        );

        if (
            $segment === ''
            || ! method_exists($model, $segment)
        ) {
            return [];
        }

        try {
            $relation = $model->{$segment}();
        } catch (Throwable) {
            return [];
        }

        if (! $relation instanceof Relation) {
            return [];
        }

        $related = $relation->getRelated();
        $tags = [];

        if ($related instanceof CacheableModel) {
            $tags[] = $related::cacheTag();
        }

        if (
            $remaining !== null
            && $remaining !== ''
        ) {
            $tags = CacheTag::merge(
                $tags,
                $this->resolveInfrastructureRelationTags(
                    $related,
                    $remaining,
                ),
            );
        }

        if (! $model->relationLoaded($segment)) {
            return CacheTag::tags(...$tags);
        }

        $loaded = $model->getRelation($segment);
        $items = $loaded instanceof Collection
            ? $loaded
            : collect([$loaded]);

        foreach ($items as $item) {
            if (! $item instanceof Model) {
                continue;
            }

            if ($item instanceof CacheableModel) {
                $tags = CacheTag::merge(
                    $tags,
                    $item->getCacheTags(),
                );
            }

            if (
                $remaining !== null
                && $remaining !== ''
            ) {
                $tags = CacheTag::merge(
                    $tags,
                    $this->resolveInfrastructureRelationTags(
                        $item,
                        $remaining,
                    ),
                );
            }
        }

        return CacheTag::tags(...$tags);
    }
}
