<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolve model-owned disk-relative directories without allowing arbitrary
 * directories, lazy relation loading, or path traversal.
 */
final class FileDirectoryTemplate
{
    /** @return list<array{literal?: string, placeholder?: string}> */
    private static function segments(string $template): array
    {
        if ($template === '' || str_contains($template, "\0")
            || str_contains($template, '\\') || str_starts_with($template, '/')
            || preg_match('/^[A-Za-z]:\//', $template) === 1) {
            throw new RuntimeException('File directory must be a safe relative path.');
        }

        $segments = [];

        foreach (explode('/', $template) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_.]*)\}$/D', $segment, $matches) === 1) {
                $segments[] = ['placeholder' => $matches[1]];

                continue;
            }

            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/^[A-Za-z0-9_.-]+$/D', $segment) !== 1
                || str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new RuntimeException('File directory contains an unsafe or unsupported segment ['.$segment.'].');
            }

            $segments[] = ['literal' => $segment];
        }

        return $segments;
    }

    public static function isDynamic(string $template): bool
    {
        foreach (self::segments($template) as $segment) {
            if (isset($segment['placeholder'])) {
                return true;
            }
        }

        return false;
    }

    public static function usesModelKey(string $template, string $keyName): bool
    {
        foreach (self::segments($template) as $segment) {
            if (isset($segment['placeholder'])
                && in_array($segment['placeholder'], ['id', $keyName], true)) {
                return true;
            }
        }

        return false;
    }

    public static function usesCreatedYear(string $template): bool
    {
        foreach (self::segments($template) as $segment) {
            if (($segment['placeholder'] ?? null) === 'created_year') {
                return true;
            }
        }

        return false;
    }

    public static function resolve(string $template, Model $model): string
    {
        $parts = [];

        foreach (self::segments($template) as $segment) {
            if (isset($segment['literal'])) {
                $parts[] = $segment['literal'];

                continue;
            }

            $name = $segment['placeholder'];
            $value = match ($name) {
                'year' => now()->format('Y'),
                'created_year' => self::createdYear($model),
                default => self::attribute($model, $name),
            };

            if (! is_scalar($value) || trim((string) $value) === ''
                || preg_match('/^[A-Za-z0-9_-]+$/D', (string) $value) !== 1) {
                throw new RuntimeException('File directory placeholder ['.$name.'] must resolve to a safe single path segment.');
            }

            $parts[] = (string) $value;
        }

        return implode('/', $parts);
    }

    /**
     * Return the static, model-owned prefix used for recursive audit scanning.
     * Dynamic templates cannot start with a placeholder (the entire disk
     * must never become an audit scope).
     */
    public static function auditRoot(string $template): string
    {
        $root = [];

        foreach (self::segments($template) as $segment) {
            if (! isset($segment['literal'])) {
                break;
            }

            $root[] = $segment['literal'];
        }

        if ($root === []) {
            throw new RuntimeException(
                'Storage audit requires a static directory prefix before dynamic placeholders.',
            );
        }

        return implode('/', $root);
    }

    /**
     * Match an actual file inside the resolved template directory, including
     * recursive subfolders. Year tokens are constrained to four digits.
     */
    public static function matchesFile(string $template, string $path): bool
    {
        $parts = explode('/', $path);
        $segments = self::segments($template);

        if (count($parts) <= count($segments)) {
            return false;
        }

        foreach ($segments as $index => $segment) {
            $actual = $parts[$index];

            if (isset($segment['literal'])) {
                if ($actual !== $segment['literal']) {
                    return false;
                }

                continue;
            }

            $expression = in_array($segment['placeholder'], ['year', 'created_year'], true)
                ? '/^[0-9]{4}$/D'
                : '/^[A-Za-z0-9_-]+$/D';

            if (preg_match($expression, $actual) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function createdYear(Model $model): string
    {
        $column = $model->getCreatedAtColumn();
        $createdAt = $column === null ? null : $model->getAttribute($column);

        if (! $createdAt instanceof DateTimeInterface) {
            throw new RuntimeException(
                'File directory placeholder [created_year] requires a valid model creation timestamp.',
            );
        }

        return $createdAt->format('Y');
    }

    private static function attribute(Model $model, string $source): mixed
    {
        $parts = explode('.', $source);
        $value = $model;

        foreach ($parts as $index => $part) {
            if (! $value instanceof Model) {
                throw new RuntimeException('Invalid directory relationship ['.$source.'].');
            }

            if ($index < count($parts) - 1) {
                if (! $value->relationLoaded($part)) {
                    throw new RuntimeException(
                        'Directory relationship ['.$part.'] must be preloaded for ['.$source.'].',
                    );
                }

                $value = $value->getRelation($part);

                continue;
            }

            $value = $value->getAttributes()[$part] ?? null;
        }

        return $value;
    }
}
