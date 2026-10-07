<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class StorageAuditCommand extends Command
{
    protected $signature = 'infrastructure:storage-audit
        {--delete : Delete orphaned files instead of only reporting them}
        {--model=* : Audit only these registered model classes or basenames}';

    protected $description = 'Audit model-owned storage directories and optionally delete files not referenced by the database.';

    public function __construct(
        private readonly FilesystemFactory $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $registered = array_values(array_unique(array_filter(
            (array) config(
                'laravel-infrastructure.storage_audit.models',
                [],
            ),
            static fn ($model): bool => is_string($model) && $model !== '',
        )));

        if ($registered === []) {
            $this->warn(
                'No storage-audit models are registered. Nothing was scanned.',
            );

            return self::SUCCESS;
        }

        try {
            $definitions = $this->modelDefinitions($registered);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $selected = $this->selectedModels(
            $registered,
            (array) $this->option('model'),
        );

        if ($selected === []) {
            $this->warn('No registered models matched --model.');

            return self::SUCCESS;
        }

        $targetScopeKeys = [];

        foreach ($selected as $modelClass) {
            foreach ($definitions[$modelClass]['scopes'] as $scopeKey => $_) {
                $targetScopeKeys[$scopeKey] = true;
            }
        }

        if ($targetScopeKeys === []) {
            $this->warn(
                'Selected models do not declare any model-owned audit directories.',
            );

            return self::SUCCESS;
        }

        // Reference collection always includes every registered model so a
        // shared model-owned directory cannot delete another model's files.
        $references = [];

        try {
            foreach ($definitions as $definition) {
                $this->collectReferences(
                    $definition['model'],
                    $definition['scopes'],
                    $references,
                );
            }
        } catch (Throwable $exception) {
            // Fail before touching storage if any database reference scan is
            // incomplete. Partial knowledge must never be used for deletion.
            $this->error(
                'Storage audit aborted before deletion: '.
                $exception->getMessage(),
            );

            return self::FAILURE;
        }

        $delete = (bool) $this->option('delete');
        $orphanCount = 0;
        $deletedCount = 0;

        foreach (array_keys($targetScopeKeys) as $scopeKey) {
            $scope = $this->scopeFromDefinitions(
                $definitions,
                $scopeKey,
            );

            if ($scope === null) {
                continue;
            }

            $disk = $scope['disk'];
            $directory = $scope['directory'];
            $filesystem = $this->files->disk($disk);
            $known = $references[$scopeKey] ?? [];

            $this->newLine();
            $this->line(
                sprintf(
                    'Auditing [%s:%s] (%d referenced path%s)',
                    $disk,
                    $directory,
                    count($known),
                    count($known) === 1 ? '' : 's',
                ),
            );

            foreach ($filesystem->allFiles($directory) as $file) {
                $path = $this->normalizePath((string) $file);

                if (! $this->belongsToDirectory($path, $directory)) {
                    continue;
                }

                if (isset($known[$path])) {
                    continue;
                }

                $orphanCount++;

                if (! $delete) {
                    $this->line('  orphan: '.$path);

                    continue;
                }

                if ($filesystem->delete($path)) {
                    $deletedCount++;
                    $this->line('  deleted: '.$path);
                } else {
                    $this->warn('  failed: '.$path);
                }
            }
        }

        $this->newLine();

        if (! $delete) {
            $this->info(
                "Dry run complete. {$orphanCount} orphaned file(s) found. ".
                'Run again with --delete to remove them.',
            );

            return self::SUCCESS;
        }

        $this->info(
            "Storage audit complete. {$orphanCount} orphaned file(s) found; ".
            "{$deletedCount} deleted.",
        );

        return $deletedCount === $orphanCount
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  list<string>  $registered
     * @return array<string, array{
     *     model:Model,
     *     scopes:array<string, array{
     *         disk:string,
     *         directory:string,
     *         columns:list<string>
     *     }>
     * }>
     */
    private function modelDefinitions(array $registered): array
    {
        $definitions = [];

        foreach ($registered as $modelClass) {
            if (! is_subclass_of($modelClass, Model::class)) {
                throw new \RuntimeException(
                    "Storage audit model [{$modelClass}] must extend ".
                    Model::class.'.',
                );
            }

            /** @var Model $model */
            $model = new $modelClass();

            if (! method_exists($model, 'auditableFileAttributes')) {
                throw new \RuntimeException(
                    "Storage audit model [{$modelClass}] must use ".
                    'InteractsWithFiles or provide auditableFileAttributes().',
                );
            }

            /** @var array<string, array<string, mixed>> $attributes */
            $attributes = $model->auditableFileAttributes();
            $scopes = [];

            foreach ($attributes as $column => $options) {
                $disk = trim((string) (
                    $options['disk']
                    ?? config(
                        'laravel-infrastructure.files.disk',
                        'public',
                    )
                ));
                $directory = $this->normalizeDirectory(
                    (string) ($options['directory'] ?? ''),
                );

                if ($disk === '' || $directory === '') {
                    continue;
                }

                $scopeKey = $this->scopeKey($disk, $directory);

                $scopes[$scopeKey] ??= [
                    'disk' => $disk,
                    'directory' => $directory,
                    'columns' => [],
                ];

                $scopes[$scopeKey]['columns'][] = $column;
                $scopes[$scopeKey]['columns'] = array_values(
                    array_unique($scopes[$scopeKey]['columns']),
                );
            }

            $definitions[$modelClass] = [
                'model' => $model,
                'scopes' => $scopes,
            ];
        }

        return $definitions;
    }

    /**
     * @param  list<string>  $registered
     * @param  list<mixed>  $requested
     * @return list<string>
     */
    private function selectedModels(
        array $registered,
        array $requested,
    ): array {
        $requested = array_values(array_filter(
            $requested,
            static fn ($value): bool => is_string($value) && $value !== '',
        ));

        if ($requested === []) {
            return $registered;
        }

        return array_values(array_filter(
            $registered,
            static function (string $modelClass) use ($requested): bool {
                $basename = class_basename($modelClass);

                return in_array($modelClass, $requested, true)
                    || in_array($basename, $requested, true);
            },
        ));
    }

    /**
     * @param  array<string, array{
     *     disk:string,
     *     directory:string,
     *     columns:list<string>
     * }>  $scopes
     * @param  array<string, array<string, true>>  $references
     */
    private function collectReferences(
        Model $model,
        array $scopes,
        array &$references,
    ): void {
        if ($scopes === []) {
            return;
        }

        $columns = [];

        foreach ($scopes as $scope) {
            $columns = array_merge(
                $columns,
                $scope['columns'],
            );
        }

        $columns = array_values(array_unique($columns));
        $keyName = $model->getKeyName();
        $select = array_values(array_unique([
            $keyName,
            ...$columns,
        ]));
        $chunkSize = max(
            1,
            (int) config(
                'laravel-infrastructure.storage_audit.chunk_size',
                500,
            ),
        );

        $model->newQueryWithoutScopes()
            ->select($select)
            ->chunkById(
                $chunkSize,
                function ($models) use (
                    $scopes,
                    &$references,
                ): void {
                    foreach ($models as $record) {
                        foreach ($scopes as $scopeKey => $scope) {
                            foreach ($scope['columns'] as $column) {
                                $value = $record->getAttribute($column);

                                if (! is_string($value) || trim($value) === '') {
                                    continue;
                                }

                                $path = $this->normalizePath($value);

                                if (! $this->belongsToDirectory(
                                    $path,
                                    $scope['directory'],
                                )) {
                                    continue;
                                }

                                $references[$scopeKey][$path] = true;
                            }
                        }
                    }
                },
                $keyName,
                $keyName,
            );
    }

    /**
     * @param  array<string, array{
     *     model:Model,
     *     scopes:array<string, array{
     *         disk:string,
     *         directory:string,
     *         columns:list<string>
     *     }>
     * }>  $definitions
     * @return array{disk:string,directory:string,columns:list<string>}|null
     */
    private function scopeFromDefinitions(
        array $definitions,
        string $scopeKey,
    ): ?array {
        foreach ($definitions as $definition) {
            if (isset($definition['scopes'][$scopeKey])) {
                return $definition['scopes'][$scopeKey];
            }
        }

        return null;
    }

    private function normalizeDirectory(string $directory): string
    {
        return trim(
            str_replace('\\', '/', trim($directory)),
            '/',
        );
    }

    private function normalizePath(string $path): string
    {
        return ltrim(
            str_replace('\\', '/', trim($path)),
            '/',
        );
    }

    private function belongsToDirectory(
        string $path,
        string $directory,
    ): bool {
        return $path === $directory
            || str_starts_with($path, $directory.'/');
    }

    private function scopeKey(
        string $disk,
        string $directory,
    ): string {
        return $disk."\0".$directory;
    }
}
