<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

trait HasBulkCache
{
    abstract protected function buildQuery(array $filters = []): Builder;

    /**
     * Bulk update records and invalidate all affected cache dependencies.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $filters
     */
    public function bulkUpdate(array $data, array $filters = []): int
    {
        $models = $this->buildQuery($filters)->get();
        $affected = 0;

        foreach ($models as $model) {
            $model->fill($data);

            if ($model->isDirty() && $model->save()) {
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * Bulk delete records and invalidate all affected cache dependencies.
     *
     * @param  array<string, mixed>  $filters
     */
    public function bulkDelete(array $filters = []): int
    {
        $models = $this->buildQuery($filters)->get();
        $affected = 0;

        foreach ($models as $model) {
            if ($model->delete()) {
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * Bulk restore soft-deleted records.
     *
     * @param  array<string, mixed>  $filters
     */
    public function bulkRestore(array $filters = []): int
    {
        $query = $this->buildQuery($filters);

        if (
            in_array(
                SoftDeletes::class,
                class_uses_recursive($query->getModel()::class),
                true,
            )
        ) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $models = $query->get();
        $affected = 0;

        foreach ($models as $model) {
            if (call_user_func([$model, 'restore'])) {
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * Bulk force-delete records.
     *
     * @param  array<string, mixed>  $filters
     */
    public function bulkForceDelete(array $filters = []): int
    {
        $query = $this->buildQuery($filters);

        if (
            in_array(
                SoftDeletes::class,
                class_uses_recursive($query->getModel()::class),
                true,
            )
        ) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $models = $query->get();
        $affected = 0;

        foreach ($models as $model) {
            if (call_user_func([$model, 'forceDelete'])) {
                $affected++;
            }
        }

        return $affected;
    }
}
