<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Console\Commands;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Files\FilenameFromModel;
use Ak279642\LaravelInfrastructure\Files\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class MediaRenameCommand extends Command
{
    protected $signature = 'infrastructure:media-rename
        {model : Fully qualified Eloquent model class}
        {--apply : Copy and update paths (default: dry run)}';

    protected $description = 'Convert public image paths to SEO filenames from filename_from.';

    public function handle(FileStorage $files): int
    {
        $class = (string) $this->argument('model');
        if (! is_subclass_of($class, Model::class)) {
            $this->error('Provide a valid fully qualified Eloquent model class.');
            return self::FAILURE;
        }
        /** @var Model $prototype */
        $prototype = new $class;
        if (! method_exists($prototype, 'configuredFileAttributes')) {
            $this->error('The model must use InteractsWithFiles.');
            return self::FAILURE;
        }

        $fields = [];
        $relations = [];
        foreach ($prototype->configuredFileAttributes() as $column => $options) {
            $sources = $options['filename_from'] ?? null;
            $disk = (string) ($options['disk'] ?? config('laravel-infrastructure.files.disk', 'public'));
            $directory = trim((string) ($options['directory'] ?? ''), '/');
            if ($sources === null || ! in_array($disk, MediaUrl::aliases(), true)) {
                continue;
            }
            if (! MediaUrl::safePath($directory)) {
                throw new RuntimeException("Invalid directory for [{$column}].");
            }
            $fields[$column] = compact('sources', 'disk', 'directory');
            foreach ((array) $sources as $source) {
                $parts = explode('.', (string) $source);
                if (count($parts) > 1) {
                    array_pop($parts);
                    $relations[] = implode('.', $parts);
                }
            }
        }
        if ($fields === []) {
            $this->warn('No public filename_from fields found.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $errors = 0;
        $changed = 0;
        $prototype->newQueryWithoutScopes()
            ->with(array_values(array_unique($relations)))
            ->chunkById(100, function ($records) use ($fields, $files, $apply, &$changed, &$errors): void {
                foreach ($records as $record) {
                    foreach ($fields as $column => $options) {
                        $old = $record->getRawOriginal($column);
                        if (! is_string($old) || $old === '') {
                            continue;
                        }
                        try {
                            $directory = $options['directory'];
                            if (! MediaUrl::safePath($old) || ! str_starts_with($old, $directory.'/')) {
                                throw new RuntimeException('File path is outside the configured directory.');
                            }
                            $stem = FilenameFromModel::resolve($record, $options['sources']);
                            $extension = strtolower((string) pathinfo($old, PATHINFO_EXTENSION));
                            if (preg_match('/^[a-z0-9]{1,20}$/D', $extension) !== 1) {
                                throw new RuntimeException('Invalid extension.');
                            }
                            $name = basename($old);
                            if (preg_match('/^'.preg_quote($stem, '/').'(?:-\d+)?\.'.
                                preg_quote($extension, '/').'$/D', $name) === 1) {
                                continue;
                            }
                            $destination = $directory.'/'.$stem.'.'.$extension;
                            $this->line("{$record->getKey()} {$column}: {$old} -> {$destination}");
                            if (! $apply) {
                                continue;
                            }
                            if (! Storage::disk($options['disk'])->exists($old)) {
                                throw new RuntimeException('Original file missing.');
                            }
                            $new = $files->copyWithUniqueName($old, $directory, $stem.'.'.$extension, $options['disk']);
                            try {
                                $affected = $record->newQueryWithoutScopes()
                                    ->whereKey($record->getKey())->where($column, $old)
                                    ->update([$column => $new]);
                                if ($affected !== 1) {
                                    throw new RuntimeException('Record changed during rename.');
                                }
                                $record->setRawAttributes(array_replace($record->getAttributes(), [$column => $new]));
                                $changed++;
                                // The conditional SQL update skips normal Eloquent events.
                                // Explicitly invalidate caches after the database path changes.
                                try {
                                    app(\Ak279642\LaravelInfrastructure\Observers\CacheObserver::class)->updated($record);
                                } catch (Throwable $cacheError) {
                                    $this->warn('File migrated; invalidate caches manually: '.$cacheError->getMessage());
                                }
                            } catch (Throwable $error) {
                                Storage::disk($options['disk'])->delete($new);
                                throw $error;
                            }
                        } catch (Throwable $error) {
                            $errors++;
                            $this->error("{$record->getKey()} {$column}: {$error->getMessage()}");
                        }
                    }
                }
            }, $prototype->getKeyName());

        $this->info(($apply ? 'Updated' : 'Dry run').' '.$changed.' paths. Errors: '.$errors);
        $this->warn('Existing files are left in place. Verify references before removing them.');
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
