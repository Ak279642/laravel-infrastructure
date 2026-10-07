<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Opt-in cache metadata and automatic invalidation for Eloquent models.
 *
 * Models using this concern should implement CacheableModel.
 */
trait InteractsWithCache
{
    public static function bootInteractsWithCache(): void
    {
        // Register observer methods as class listeners instead of calling
        // Model::observe() while the model is booting. This avoids recursive
        // model construction on Laravel 13 while preserving the observer's
        // ShouldHandleEventsAfterCommit behavior.
        static::created(CacheObserver::class.'@created');
        static::updated(CacheObserver::class.'@updated');
        static::deleted(CacheObserver::class.'@deleted');

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
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
            : CacheTag::model(static::cacheTag(), $id);
    }

    public function getCacheDependencyTags(mixed $result = null, array $relations = []): array
    {
        $tags = [static::cacheTag()];

        if ($result instanceof CacheableModel) {
            $tags = CacheTag::merge($tags, $result->getCacheTags());
        }

        foreach ($relations as $relation) {
            if (! is_string($relation) || $relation === '') {
                continue;
            }

            $relation = trim(explode(':', $relation, 2)[0]);
            $root = explode('.', $relation)[0] ?? '';

            if ($root === '' || ! method_exists($this, $root)) {
                continue;
            }

            try {
                $relationObject = $this->{$root}();
            } catch (Throwable) {
                continue;
            }

            if ($relationObject instanceof Relation) {
                $related = $relationObject->getRelated();

                if ($related instanceof CacheableModel) {
                    $tags[] = $related::cacheTag();
                }
            }

            if ($this->relationLoaded($root)) {
                $loaded = $this->getRelation($root);
                $items = $loaded instanceof Collection
                    ? $loaded
                    : collect([$loaded]);

                foreach ($items as $item) {
                    if ($item instanceof CacheableModel) {
                        $tags = CacheTag::merge(
                            $tags,
                            $item->getCacheTags(),
                        );
                    }
                }
            }
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

    public function invalidateCache(): void
    {
        // Hook for application models that maintain additional cache state.
    }
}
