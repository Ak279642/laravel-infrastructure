<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Models\Concerns;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;

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
        static::creating(function (Model $model): void {
            $model->storeInfrastructureUploadedFiles();
        });

        static::updating(function (Model $model): void {
            $model->storeInfrastructureUploadedFiles();
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

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted(function (Model $model): void {
                $model->deleteInfrastructureModelFiles();
            });
        }
    }

    /**
     * Override shared file behavior on an application base model.
     *
     * @return array<string, mixed>
     */
    protected function fileOptions(): array
    {
        return [];
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
        $defaults = array_replace(
            (array) config('laravel-infrastructure.files', []),
            $this->fileOptions(),
        );
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

    /**
     * Return only file attributes whose audit directory is explicitly owned by
     * the model configuration. Global package directories are never inferred
     * for storage auditing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function auditableFileAttributes(): array
    {
        $modelOptions = $this->fileOptions();
        $configured = [];

        foreach ($this->fileAttributes() as $key => $value) {
            $column = is_int($key) && is_string($value)
                ? $value
                : (is_string($key) ? $key : null);

            if (! is_string($column) || $column === '') {
                continue;
            }

            $attributeOptions = is_array($value) ? $value : [];

            if (! (bool) ($attributeOptions['audit'] ?? $modelOptions['audit'] ?? true)) {
                continue;
            }

            // Storage audit is intentionally restricted to a directory that is
            // declared by the model itself. We do not fall back to the package
            // global files.directory value here.
            $directory = $attributeOptions['directory']
                ?? $modelOptions['directory']
                ?? null;

            if (! is_string($directory) || trim($directory, '/') === '') {
                continue;
            }

            $configured[$column] = array_replace(
                (array) config('laravel-infrastructure.files', []),
                $modelOptions,
                $attributeOptions,
                [
                    'directory' => trim($directory, '/'),
                ],
            );
        }

        return $configured;
    }

    private function storeInfrastructureUploadedFiles(): void
    {
        $storage = app(FileStorage::class);

        foreach ($this->configuredFileAttributes() as $column => $options) {
            if (! (bool) ($options['auto_upload'] ?? true)) {
                continue;
            }

            $file = $this->getAttribute($column);

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $directory = (string) (
                $options['directory']
                ?? config('laravel-infrastructure.files.directory', 'uploads')
            );
            $disk = (string) (
                $options['disk']
                ?? config('laravel-infrastructure.files.disk', 'public')
            );

            $filename = $options['filename'] ?? null;

            if (is_callable($filename)) {
                $filename = $filename($file, $this, $column);
            }

            if (! is_string($filename)) {
                $filename = null;
            }

            $this->setAttribute(
                $column,
                $storage->store(
                    file: $file,
                    directory: $directory,
                    disk: $disk,
                    filename: $filename,
                ),
            );
        }
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
