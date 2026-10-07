<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Models\Concerns;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

trait InteractsWithFiles
{
    /**
     * @var list<array{attribute:string,path:string,disk:string}>
     */
    private array $infrastructurePendingFileDeletes = [];

    public static function bootInteractsWithFiles(): void
    {
        static::updating(function (Model $model): void {
            $model->captureInfrastructureChangedFiles();
        });

        static::updated(function (Model $model): void {
            $model->scheduleInfrastructurePendingFiles();
        });

        static::deleted(function (Model $model): void {
            $usesSoftDeletes = in_array(
                SoftDeletes::class,
                class_uses_recursive($model),
                true,
            );

            $forceDeleting = method_exists(
                $model,
                'isForceDeleting',
            ) && $model->isForceDeleting();

            if (
                $usesSoftDeletes
                && ! $forceDeleting
            ) {
                $model->scheduleInfrastructureModelFiles(
                    softDelete: true,
                );

                return;
            }

            if (! $usesSoftDeletes) {
                $model->scheduleInfrastructureModelFiles();
            }
        });

        if (
            in_array(
                SoftDeletes::class,
                class_uses_recursive(static::class),
                true,
            )
        ) {
            static::forceDeleted(function (Model $model): void {
                $model->scheduleInfrastructureModelFiles();
            });
        }
    }

    protected function fileOptions(): array
    {
        return [];
    }

    /**
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
            (array) config(
                'laravel-infrastructure.files',
                [],
            ),
            $this->fileOptions(),
        );

        $configured = [];

        foreach ($this->fileAttributes() as $key => $value) {
            if (
                is_int($key)
                && is_string($value)
            ) {
                $configured[$value] = $defaults;

                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $configured[$key] = array_replace(
                $defaults,
                is_array($value)
                    ? $value
                    : [],
            );
        }

        return $configured;
    }

    private function captureInfrastructureChangedFiles(): void
    {
        $pending = [];

        foreach ($this->configuredFileAttributes() as $column => $options) {
            if (
                ! (bool) (
                    $options['delete_on_replace']
                    ?? true
                )
                || ! $this->isDirty($column)
            ) {
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

            $disk = (string) (
                $options['disk']
                ?? config(
                    'laravel-infrastructure.files.disk',
                    'public',
                )
            );

            $pending[$disk.'|'.$oldPath] = [
                'attribute' => $column,
                'path' => $oldPath,
                'disk' => $disk,
            ];
        }

        $this->infrastructurePendingFileDeletes = array_values(
            $pending,
        );
    }

    private function scheduleInfrastructurePendingFiles(): void
    {
        if ($this->infrastructurePendingFileDeletes === []) {
            return;
        }

        $pending = $this->infrastructurePendingFileDeletes;
        $this->infrastructurePendingFileDeletes = [];

        $this->afterInfrastructureCommit(
            function () use ($pending): void {
                foreach ($pending as $file) {
                    if ($this->infrastructurePathStillReferenced(
                        $file['path'],
                        $file['disk'],
                    )) {
                        continue;
                    }

                    $this->deleteInfrastructureFile(
                        $file['attribute'],
                        $file['path'],
                        $file['disk'],
                    );
                }
            },
        );
    }

    private function scheduleInfrastructureModelFiles(
        bool $softDelete = false,
    ): void {
        $files = [];

        foreach ($this->configuredFileAttributes() as $column => $options) {
            if (
                $softDelete
                && ! (bool) (
                    $options['delete_on_soft_delete']
                    ?? false
                )
            ) {
                continue;
            }

            if (
                ! $softDelete
                && ! (bool) (
                    $options['delete_on_delete']
                    ?? true
                )
            ) {
                continue;
            }

            $path = $this->getAttribute($column);

            if (
                ! is_string($path)
                || trim($path) === ''
            ) {
                continue;
            }

            $disk = (string) (
                $options['disk']
                ?? config(
                    'laravel-infrastructure.files.disk',
                    'public',
                )
            );

            $files[$disk.'|'.$path] = [
                'attribute' => $column,
                'path' => $path,
                'disk' => $disk,
            ];
        }

        if ($files === []) {
            return;
        }

        $this->afterInfrastructureCommit(
            function () use ($files): void {
                foreach ($files as $file) {
                    $this->deleteInfrastructureFile(
                        $file['attribute'],
                        $file['path'],
                        $file['disk'],
                    );
                }
            },
        );
    }

    private function afterInfrastructureCommit(
        callable $callback,
    ): void {
        $connection = $this->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    private function infrastructurePathStillReferenced(
        string $path,
        string $disk,
    ): bool {
        foreach ($this->configuredFileAttributes() as $column => $options) {
            $configuredDisk = (string) (
                $options['disk']
                ?? config(
                    'laravel-infrastructure.files.disk',
                    'public',
                )
            );

            if (
                $configuredDisk === $disk
                && $this->getAttribute($column) === $path
            ) {
                return true;
            }
        }

        return false;
    }

    private function deleteInfrastructureFile(
        string $attribute,
        string $path,
        string $disk,
    ): void {
        try {
            app(FileStorage::class)->delete(
                $path,
                $disk,
            );
        } catch (Throwable $exception) {
            if (
                (bool) config(
                    'laravel-infrastructure.files.throw_on_cleanup_failure',
                    false,
                )
            ) {
                throw $exception;
            }

            CustomLog::warning(
                'File lifecycle cleanup failed.',
                [
                    'model' => static::class,
                    'attribute' => $attribute,
                    'disk' => $disk,
                    'exception' => $exception,
                ],
            );
        }
    }
}
