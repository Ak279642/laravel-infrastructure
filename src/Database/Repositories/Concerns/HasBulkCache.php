<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasBulkCache
{
    abstract protected function buildQuery(array $filters = []): Builder;

    abstract public function clearCache(): void;

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

    public function bulkDelete(
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

    public function bulkRestore(
        array $filters = [],
    ): int {
        $models = $this
            ->buildQuery($filters)
            ->get();

        $affected = 0;

        foreach ($models as $model) {
            if (
                method_exists($model, 'restore')
                && $model->restore()
            ) {
                $affected++;
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }

    public function bulkForceDelete(
        array $filters = [],
    ): int {
        $models = $this
            ->buildQuery($filters)
            ->get();

        $affected = 0;

        foreach ($models as $model) {
            if (
                method_exists($model, 'forceDelete')
                && $model->forceDelete()
            ) {
                $affected++;
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }
}
