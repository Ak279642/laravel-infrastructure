<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 * @mixin \Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository<TModel>
 */
trait HasBulkCache
{
    /** @param array<string, mixed> $filters @return Builder<TModel> */
    abstract protected function buildQuery(array $filters = []): Builder;

    abstract public function clearCache(): void;

    /** @param array<string, mixed> $data @param array<string, mixed> $filters */
    public function bulkUpdate(
        array $data,
        array $filters = [],
    ): int {
        $models = $this
            ->buildQuery($filters)
            ->get();

        $affected = 0;

        foreach ($models as $model) {
            $model->fill($data);

            if (
                $model->isDirty()
                && $model->save()
            ) {
                $affected++;
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }
\n    /** @param array<string, mixed> $filters */\n    public function bulkDelete(
        array $filters = [],
    ): int {
        $models = $this
            ->buildQuery($filters)
            ->get();

        $affected = 0;

        foreach ($models as $model) {
            if ($model->delete()) {
                $affected++;
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }
\n    /** @param array<string, mixed> $filters */\n    public function bulkRestore(
        array $filters = [],
    ): int {
        $models = $this
            ->buildQuery($filters)
            ->get();

        $affected = 0;

        foreach ($models as $model) {
            if ($model->restore()) {
                $affected++;
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }
\n    /** @param array<string, mixed> $filters */\n    public function bulkForceDelete(
        array $filters = [],
    ): int {
        $models = $this
            ->buildQuery($filters)
            ->get();

        $affected = 0;

        foreach ($models as $model) {
            if ($model->forceDelete()) {
                $affected++;
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }
}
