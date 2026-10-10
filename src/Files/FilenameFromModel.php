<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

final class FilenameFromModel
{
    /** Never lazy-load relationships or attributes during an upload. */
    public static function resolve(Model $model, string|array $sources): string
    {
        $sources = is_string($sources) ? [$sources] : $sources;
        if ($sources === []) {
            throw new RuntimeException('filename_from cannot be empty.');
        }

        $values = [];
        foreach ($sources as $source) {
            if (! is_string($source) || $source === '') {
                throw new RuntimeException('filename_from must contain attribute paths.');
            }
            $parts = explode('.', $source);
            $value = $model;
            foreach ($parts as $index => $part) {
                if (! $value instanceof Model) {
                    throw new RuntimeException("Invalid filename_from relation [{$source}].");
                }
                if ($index !== count($parts) - 1) {
                    if (! $value->relationLoaded($part)) {
                        throw new RuntimeException("Relationship [{$part}] must be loaded for filename_from [{$source}].");
                    }
                    $value = $value->getRelation($part);
                } else {
                    $value = $value->getAttributes()[$part] ?? null;
                }
            }
            if (! is_scalar($value) || trim((string) $value) === '') {
                throw new RuntimeException("Missing filename_from attribute [{$source}].");
            }
            $values[] = (string) $value;
        }

        $name = trim(substr(Str::slug(implode('-', $values)), 0, 180), '-');
        if ($name === '') {
            throw new RuntimeException('filename_from generated an empty filename.');
        }

        return $name;
    }
}
