<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Slugs;

use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

final class SlugLookupRepository extends BaseRepository
{
    /**
     * @param  array<string, mixed>  $where
     */
    public function matching(
        string $column,
        string $baseSlug,
        array $where = [],
        int|string|null $ignoreId = null,
    ): Collection {
        $column = $this->safeModelColumn($column);
        $where = $this->safeWhere($where);
        $query = $this->query();

        if (
            in_array(
                SoftDeletes::class,
                class_uses_recursive($this->getModel()::class),
                true,
            )
        ) {
            $query->withTrashed();
        }

        $query->where($column, 'LIKE', $baseSlug.'%');

        foreach ($where as $field => $value) {
            $query->where($field, $value);
        }

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->pluck($column);
    }
}
