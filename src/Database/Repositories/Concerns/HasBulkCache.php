<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

trait HasBulkCache
{
    abstract protected function buildQuery(array $filters = []): Builder;

    abstract protected function rememberValidationValue(Model|Collection $value): void;

    abstract protected function forgetValidationModel(Model $model): void;

    abstract public function clearCache(): void;

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
                $this->rememberValidationValue($model);
            }
        }

        if ($affected > 0) {
            $this->clearCache();
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
                $this->forgetValidationModel($model);
            }
        }

        if ($affected > 0) {
            $this->clearCache();
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

        if (! $this->usesSoftDeletes($query)) {
            return 0;
        }

        $query->withoutGlobalScope(SoftDeletingScope::class);

        $models = $query->get();
        $affected = 0;

        foreach ($models as $model) {
            if (call_user_func([$model, 'restore'])) {
                $affected++;
                $this->rememberValidationValue($model);
            }
        }

        if ($affected > 0) {
            $this->clearCache();
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
        $usesSoftDeletes = $this->usesSoftDeletes($query);

        if ($usesSoftDeletes) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $models = $query->get();
        $affected = 0;

        foreach ($models as $model) {
            $deleted = $usesSoftDeletes
                ? (bool) call_user_func([$model, 'forceDelete'])
                : (bool) $model->delete();

            if ($deleted) {
                $affected++;
                $this->forgetValidationModel($model);
            }
        }

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }

    private function usesSoftDeletes(Builder $query): bool
    {
        return in_array(
            SoftDeletes::class,
            class_uses_recursive($query->getModel()::class),
            true,
        );
    }
}
