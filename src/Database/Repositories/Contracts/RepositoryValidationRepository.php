<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryValidationRepository
{
    public function getModel(): Model;

    public function findDuplicate(
        array $fields,
        int|string|null $ignore = null,
        array $where = [],
    ): ?Model;

    public function findWhere(
        mixed $id,
        array $where = [],
        array $with = [],
    ): ?Model;

    public function findWhereIn(
        string $field,
        array $values,
        array $where = [],
    ): Collection;

    public function loadMissing(
        Model $model,
        array|string $relations,
    ): Model;
}
