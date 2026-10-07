<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Models\Concerns;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

trait InteractsWithFiles
{
    /**
     * Files queued here are removed only after a successful update.
     *
     * @var list<array{path:string,disk:string}>
     */
    private array $infrastructurePendingFileDeletes = [];

    public static function bootInteractsWithFiles(): void
    {
        static::updating(function (Model $model): void {
            $model->captureInfrastructureChangedFiles();
        });

        static::updated(function (Model $model): void {
            $model->deleteInfrastructurePendingFiles();
        });

        static::deleted(function (Model $model): void {
            $usesSoftDeletes = in_array(
                SoftDeletes::class,
                class_uses_recursive($model),
                true,
            );

            if ($usesSoftDeletes) {
                $model->deleteInfrastructureModelFiles(softDelete: true);

                return;
            }

            $model->deleteInfrastructureModelFiles();
        });

        static::forceDeleted(function (Model $model): void {
            $model->deleteInfrastructureModelFiles();
        });
    }

    /**
     * Configure file-path attributes on the model.
     *
     * Examples:
     * [
     *     'avatar',
     *     'document' => ['disk' => 'private'],
     * ]
     *
     * @return array<int|string, string|array<string, mixed>>
     */
    protected function fileAttributes(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function configuredFileAttributes(): array
    {
        $defaults = (array) config('laravel-infrastructure.files', []);
        $configured = [];

        foreach ($this->fileAttributes() as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $configured[$value] = $defaults;

                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $configured[$key] = array_replace(
                $defaults,
                is_array($value) ? $value : [],
            );
        }

        return $configured;
    }

    private function captureInfrastructureChangedFiles(): void
    {
        foreach ($this->configuredFileAttributes() as $column => $options) {
            if (! (bool) ($options['delete_on_replace'] ?? true)) {
                continue;
            }

            if (! $this->isDirty($column)) {
                continue;
            }

            $oldPath = $this->getRawOriginal($column);
            $newPath = $this->getAttribute($column);

            if (
                ! is_string($oldPath)
                || trim($oldPath) === ''
                || $oldPath === $newPath
            ) {
                continue;
            }

            $this->infrastructurePendingFileDeletes[] = [
                'path' => $oldPath,
                'disk' => (string) ($options['disk']
                    ?? config('laravel-infrastructure.files.disk', 'public')),
            ];
        }
    }

    private function deleteInfrastructurePendingFiles(): void
    {
        if ($this->infrastructurePendingFileDeletes === []) {
            return;
        }

        $storage = app(FileStorage::class);

        foreach ($this->infrastructurePendingFileDeletes as $file) {
            $storage->delete($file['path'], $file['disk']);
        }

        $this->infrastructurePendingFileDeletes = [];
    }

    private function deleteInfrastructureModelFiles(bool $softDelete = false): void
    {
        $storage = app(FileStorage::class);

        foreach ($this->configuredFileAttributes() as $column => $options) {
            if ($softDelete && ! (bool) ($options['delete_on_soft_delete'] ?? false)) {
                continue;
            }

            if (! $softDelete && ! (bool) ($options['delete_on_delete'] ?? true)) {
                continue;
            }

            $path = $this->getAttribute($column);

            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $storage->delete(
                $path,
                (string) ($options['disk']
                    ?? config('laravel-infrastructure.files.disk', 'public')),
            );
        }
    }
}
