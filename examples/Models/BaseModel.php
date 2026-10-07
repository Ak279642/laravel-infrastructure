<?php

declare(strict_types=1);

namespace App\Models;

use Ak279642\LaravelInfrastructure\Models\BaseModel as InfrastructureBaseModel;

/**
 * Application base model.
 *
 * All application models extending this class inherit:
 * - package cache invalidation
 * - configurable slug generation
 * - configurable file lifecycle cleanup
 */
abstract class BaseModel extends InfrastructureBaseModel
{
    protected function slugOptions(): array
    {
        return [
            'enabled' => true,
            'source' => ['name', 'title'],
            'column' => 'slug',
            'unique' => true,
            'regenerate_on_update' => false,
            'separator' => '-',
        ];
    }

    protected function fileOptions(): array
    {
        return [
            'disk' => 'public',
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
        ];
    }
}
