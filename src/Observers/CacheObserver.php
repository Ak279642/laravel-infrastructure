<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Observers;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class CacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly CacheInvalidator $invalidator,
    ) {}

    public function created(Model $model, ?array $tags = null): void
    {
        $this->invalidate($model, 'created', $tags);
    }

    public function updated(Model $model, ?array $tags = null): void
    {
        $this->invalidate($model, 'updated', $tags);
    }

    public function deleted(Model $model, ?array $tags = null): void
    {
        $this->invalidate($model, 'deleted', $tags);
    }

    public function restored(Model $model, ?array $tags = null): void
    {
        $this->invalidate($model, 'restored', $tags);
    }

    public function forceDeleted(Model $model, ?array $tags = null): void
    {
        $this->invalidate($model, 'forceDeleted', $tags);
    }

    private function invalidate(Model $model, string $event, ?array $tags = null): void
    {
        if (! $model instanceof CacheableModel) {
            return;
        }

        if (
            method_exists($model, 'usesInfrastructureCache')
            && ! $model->usesInfrastructureCache()
        ) {
            return;
        }

        $tags ??= $model->getCacheInvalidationTags();

        if ($tags === []) {
            return;
        }
        // Log::info('Observer invalidating cache after commit', [
        //     'model' => $model::class,
        //     'id' => $model->getKey(),
        //     'event' => $event,
        //     'tags' => $tags,
        // ]);

        $this->invalidator->invalidateTags($tags);

        $model->invalidateCache();
    }
}
