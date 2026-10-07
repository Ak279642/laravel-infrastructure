<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Observers;

use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class CacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly CacheInvalidator $invalidator,
    ) {}

    public function created(Model $model): void
    {
        $this->invalidate($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model, 'deleted');
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model, 'restored');
    }

    public function forceDeleted(Model $model): void
    {
        $this->invalidate($model, 'forceDeleted');
    }

    private function invalidate(Model $model, string $event): void
    {
        if (!$model instanceof CacheableModel) {
            return;
        }

        $tags = $model->getCacheInvalidationTags();

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
