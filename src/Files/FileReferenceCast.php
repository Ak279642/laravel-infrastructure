<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

final class FileReferenceCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): FileReference|UploadedFile|null
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }
        return is_string($value) && $value !== ''
            ? new FileReference($model, $key, $value)
            : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string|UploadedFile|null
    {
        return $value instanceof FileReference ? $value->path : $value;
    }
}
