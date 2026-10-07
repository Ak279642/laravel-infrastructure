<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Models\Concerns;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Files\ImageProcessor;
use Ak279642\LaravelInfrastructure\Files\PendingFileUploads;
use Illuminate\Database\Eloquent\SoftDeletes;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

trait InteractsWithFiles
{
    /**
     * Files queued here are removed only after a successful update.
     *
     * @var list<array{path:string,disk:string}>
     */
    private array $infrastructurePendingFileDeletes = [];

    /**
     * Files created during the current save attempt.
     *
     * @var list<array{column:string,path:string,disk:string}>
     */
    private array $infrastructureUploadedDuringSave = [];

    private bool $infrastructureExistedBeforeSave = false;

    public function save(array $options = [])
    {
        $this->infrastructureUploadedDuringSave = [];
        $this->infrastructurePendingFileDeletes = [];
        $this->infrastructureExistedBeforeSave = $this->exists;

        try {
            $saved = parent::save($options);
        } catch (Throwable $exception) {
            $this->discardInfrastructureUnpersistedUploads();

            throw $exception;
        }

        if (! $saved) {
            $this->discardInfrastructureUnpersistedUploads();

            return false;
        }

        $this->infrastructureUploadedDuringSave = [];

        return true;
    }

    public static function bootInteractsWithFiles(): void
    {
        static::creating(function (self $model): void {
            $model->storeInfrastructureUploadedFiles();
        });

        static::updating(function (self $model): void {
            $model->storeInfrastructureUploadedFiles();
            $model->captureInfrastructureChangedFiles();
        });

        static::updated(function (self $model): void {
            $model->deleteInfrastructurePendingFiles();
        });

        static::deleted(function (self $model): void {
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
            static::registerModelEvent(
                'forceDeleted',
                function (self $model): void {
                    $model->deleteInfrastructureModelFiles();
                },
            );
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

    public function fileAssetUrl(
        string $column,
        ?DateTimeInterface $expiration = null,
    ): ?string {
        $options = $this->configuredFileAttributes()[$column] ?? null;
        $path = $this->getAttribute($column);

        if (
            ! is_array($options)
            || ! is_string($path)
            || trim($path) === ''
            || ! $this->exists
            || $this->getKey() === null
        ) {
            return null;
        }

        $access = is_array($options['access'] ?? null)
            ? $options['access']
            : [];
        $signed = array_key_exists('signed', $access)
            ? (bool) $access['signed']
            : (bool) config(
                'laravel-infrastructure.assets.signed',
                true,
            );

        $parameters = [
            'resource' => $this->infrastructureAssetResourceAlias(),
            'key' => (string) $this->getKey(),
            'field' => $column,
        ];

        if (! $signed) {
            return route(
                'laravel-infrastructure.assets.model',
                $parameters,
            );
        }

        return URL::temporarySignedRoute(
            'laravel-infrastructure.assets.model',
            $expiration
                ?? now()->addMinutes(
                    max(
                        1,
                        (int) config(
                            'laravel-infrastructure.assets.url_ttl_minutes',
                            15,
                        ),
                    ),
                ),
            $parameters,
        );
    }

    private function infrastructureAssetResourceAlias(): string
    {
        $resources = (array) config(
            'laravel-infrastructure.assets.resources',
            [],
        );

        foreach ($resources as $alias => $modelClass) {
            if (
                ! is_string($alias)
                || preg_match('/^[A-Za-z0-9_-]+$/', $alias) !== 1
                || $modelClass !== static::class
            ) {
                continue;
            }

            return $alias;
        }

        throw new RuntimeException(
            'No valid asset resource alias is configured for model ['.
            static::class.
            ']. Add it to laravel-infrastructure.assets.resources.',
        );
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

            $image = $options['image'] ?? null;
            $imageOptions = is_array($image)
                ? $image
                : [];

            if ($image === true) {
                $imageOptions['enabled'] = true;
            }

            $path = (bool) ($imageOptions['enabled'] ?? false)
                ? app(ImageProcessor::class)->store(
                    file: $file,
                    directory: $directory,
                    disk: $disk,
                    filename: $filename,
                    options: $imageOptions,
                )
                : $storage->store(
                    file: $file,
                    directory: $directory,
                    disk: $disk,
                    filename: $filename,
                );

            $upload = [
                'column' => $column,
                'path' => $path,
                'disk' => $disk,
            ];

            $this->infrastructureUploadedDuringSave[] = $upload;

            $connection = $this->getConnection();

            if ($connection->transactionLevel() > 0) {
                app(PendingFileUploads::class)->track(
                    (string) $connection->getName(),
                    $connection->transactionLevel(),
                    $path,
                    $disk,
                );
            }

            $this->setAttribute($column, $path);
        }
    }

    private function discardInfrastructureUnpersistedUploads(): void
    {
        if ($this->infrastructureUploadedDuringSave === []) {
            $this->infrastructurePendingFileDeletes = [];

            return;
        }

        $discard = [];

        foreach ($this->infrastructureUploadedDuringSave as $upload) {
            if ($this->infrastructureUploadWasPersisted($upload)) {
                continue;
            }

            $discard[] = [
                'path' => $upload['path'],
                'disk' => $upload['disk'],
            ];
        }

        if ($discard !== []) {
            $connection = $this->getConnection();

            app(PendingFileUploads::class)->discard(
                $discard,
                (string) $connection->getName(),
            );
        }

        $this->infrastructureUploadedDuringSave = [];
        $this->infrastructurePendingFileDeletes = [];
    }

    /**
     * @param  array{column:string,path:string,disk:string}  $upload
     */
    private function infrastructureUploadWasPersisted(array $upload): bool
    {
        if (! $this->infrastructureExistedBeforeSave) {
            return $this->exists;
        }

        $key = $this->getKey();

        if ($key === null) {
            return false;
        }

        try {
            return (string) $this->newQueryWithoutRelationships()
                ->whereKey($key)
                ->value($upload['column']) === $upload['path'];
        } catch (Throwable) {
            // If the connection is already in a failed transactional state,
            // rollback tracking remains responsible for cleanup. Avoid deleting
            // a file that may already be referenced by a successful DB write.
            return $this->getConnection()->transactionLevel() > 0;
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

        $files = $this->infrastructurePendingFileDeletes;
        $this->infrastructurePendingFileDeletes = [];

        $this->deleteInfrastructureFilesAfterCommit($files);
    }

    private function deleteInfrastructureModelFiles(bool $softDelete = false): void
    {
        $files = [];

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

            $files[] = [
                'path' => $path,
                'disk' => (string) ($options['disk']
                    ?? config('laravel-infrastructure.files.disk', 'public')),
            ];
        }

        $this->deleteInfrastructureFilesAfterCommit($files);
    }

    /**
     * @param  list<array{path:string,disk:string}>  $files
     */
    private function deleteInfrastructureFilesAfterCommit(array $files): void
    {
        if ($files === []) {
            return;
        }

        $delete = static function () use ($files): void {
            $storage = app(FileStorage::class);

            foreach ($files as $file) {
                $storage->delete($file['path'], $file['disk']);
            }
        };

        $connection = $this->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($delete);

            return;
        }

        $delete();
    }
}
