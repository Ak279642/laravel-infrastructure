<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Models;

use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Models\Concerns\InteractsWithFiles;
use Ak279642\LaravelInfrastructure\Models\Concerns\InteractsWithSlug;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model implements CacheableModel
{
    use InteractsWithCache;
    use InteractsWithSlug;
    use InteractsWithFiles;
}
