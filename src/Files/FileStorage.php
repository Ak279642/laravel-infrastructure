<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

final class FileStorage
{
    public function __construct(
        private readonly FilesystemFactory $files,
    ) {}

    public function store(
        UploadedFile $file,
        ?string $directory = null,
        ?string $disk = null,
        ?string $filename = null,
    ): string {
        $disk = $disk ?: (string) config('laravel-infrastructure.files.disk', 'public');
        $directory = $this->normalizeDirectory(
            $directory ?? (string) config(
                'laravel-infrastructure.files.directory',
                'uploads',
            ),
        );

        $filename = $this->normalizeFilename($file, $filename);

        return $this->saveWithoutOverwrite($disk, $directory, $filename, function (string $name) use ($disk, $directory, $file): string {
            $path = $this->files->disk($disk)->putFileAs($directory, $file, $name);
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to store uploaded file.');
            }
            return $path;
        });
    }

    public function storeContents(
        string $contents,
        string $extension,
        ?string $directory = null,
        ?string $disk = null,
        ?string $filename = null,
    ): string {
        $disk = $disk ?: (string) config(
            'laravel-infrastructure.files.disk',
            'public',
        );
        $directory = $this->normalizeDirectory(
            $directory ?? (string) config(
                'laravel-infrastructure.files.directory',
                'uploads',
            ),
        );
        $extension = $this->normalizeExtension($extension);
        $filename = $this->normalizeFilenameForExtension(
            $extension,
            $filename,
        );
        return $this->saveWithoutOverwrite($disk, $directory, $filename, function (string $name) use ($disk, $directory, $contents): string {
            $path = $directory.'/'.$name;
            if (! $this->files->disk($disk)->put($path, $contents)) {
                throw new RuntimeException('Unable to store generated file contents.');
            }
            return $path;
        });
    }

    public function delete(?string $path, ?string $disk = null): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $path = $this->normalizeStoredPath($path);
        $disk = $disk ?: (string) config('laravel-infrastructure.files.disk', 'public');
        $filesystem = $this->files->disk($disk);

        if (! $filesystem->exists($path)) {
            return false;
        }

        return $filesystem->delete($path);
    }

    public function exists(?string $path, ?string $disk = null): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $path = $this->normalizeStoredPath($path);
        $disk = $disk ?: (string) config('laravel-infrastructure.files.disk', 'public');

        return $this->files->disk($disk)->exists($path);
    }

    public function url(?string $path, ?string $disk = null): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = $this->normalizeStoredPath($path);
        $disk = $disk ?: (string) config('laravel-infrastructure.files.disk', 'public');
        $filesystem = $this->files->disk($disk);

        if (! method_exists($filesystem, 'url')) {
            return null;
        }

        return $filesystem->url($path);
    }


    /**
     * All package uploads to a directory share a cache lock so that collision
     * checks and writes are serialized. Multi-node deployments need a shared
     * atomic-lock-capable cache store, such as Redis.
     */
    private function saveWithoutOverwrite(string $disk, string $directory, string $filename, callable $write): string
    {
        $lock = Cache::lock('infrastructure:upload:'.hash('sha256', $disk.'|'.$directory), 120);

        return $lock->block(30, function () use ($disk, $directory, $filename, $write): string {
            $filesystem = $this->files->disk($disk);
            $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
            $stem = (string) pathinfo($filename, PATHINFO_FILENAME);

            for ($index = 1; $index <= 10000; $index++) {
                $candidate = $index === 1 ? $filename : $stem.'-'.$index.
                    ($extension === '' ? '' : '.'.$extension);
                if (! $filesystem->exists($directory.'/'.$candidate)) {
                    return $write($candidate);
                }
            }

            throw new RuntimeException('Unable to allocate a unique file name.');
        });
    }

    /** Copy a file to a new collision-safe name without deleting its original. */
    public function copyWithUniqueName(
        string $oldPath,
        string $directory,
        string $filename,
        string $disk = 'public',
    ): string {
        $oldPath = $this->normalizeStoredPath($oldPath);
        $directory = $this->normalizeDirectory($directory);
        $filename = $this->normalizeFilenameForExtension('', $filename);
        return $this->saveWithoutOverwrite($disk, $directory, $filename, function (string $name) use ($disk, $directory, $oldPath): string {
            $destination = $directory.'/'.$name;
            if (! $this->files->disk($disk)->copy($oldPath, $destination)) {
                throw new RuntimeException('Unable to copy media file.');
            }
            return $destination;
        });
    }

    private function normalizeStoredPath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('Storage path contains a null byte.');
        }

        $path = str_replace('\\', '/', trim($path));

        if (
            $path === ''
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
        ) {
            throw new RuntimeException('Storage path must be relative.');
        }

        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1
            ) {
                throw new RuntimeException(
                    'Storage path contains an unsafe path segment.',
                );
            }
        }

        return $path;
    }

    private function normalizeDirectory(string $directory): string
    {
        if (str_contains($directory, "\0")) {
            throw new RuntimeException('Storage directory contains a null byte.');
        }

        $directory = str_replace('\\', '/', trim($directory));

        if (
            $directory === ''
            || str_starts_with($directory, '/')
            || preg_match('/^[A-Za-z]:\//', $directory) === 1
        ) {
            throw new RuntimeException('Storage directory must be a relative path.');
        }

        $segments = explode('/', trim($directory, '/'));

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1
            ) {
                throw new RuntimeException('Storage directory contains an unsafe path segment.');
            }
        }

        return implode('/', $segments);
    }

    private function normalizeFilename(
        UploadedFile $file,
        ?string $filename,
    ): string {
        return $this->normalizeFilenameForExtension(
            $this->normalizeExtension(
                $file->getClientOriginalExtension(),
                allowEmpty: true,
            ),
            $filename,
        );
    }

    private function normalizeFilenameForExtension(
        string $extension,
        ?string $filename,
    ): string {
        if (is_string($filename) && trim($filename) !== '') {
            $filename = trim($filename);

            if (
                str_contains($filename, "\0")
                || str_contains($filename, '/')
                || str_contains($filename, '\\')
                || str_starts_with($filename, '.')
                || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
            ) {
                throw new RuntimeException('Filename contains unsafe characters.');
            }

            if ($filename === '.' || $filename === '..') {
                throw new RuntimeException('Filename is invalid.');
            }

            $providedExtension = strtolower(
                (string) pathinfo($filename, PATHINFO_EXTENSION),
            );

            if (
                $providedExtension !== ''
                && preg_match('/^[a-z0-9]{1,20}$/', $providedExtension) !== 1
            ) {
                throw new RuntimeException('Filename extension is invalid.');
            }

            if ($extension !== '' && $providedExtension === '') {
                $filename .= '.'.$extension;
            }

            return $filename;
        }

        $name = (string) Str::uuid();

        return $extension === ''
            ? $name
            : $name.'.'.$extension;
    }

    private function normalizeExtension(
        string $extension,
        bool $allowEmpty = false,
    ): string {
        $extension = strtolower(trim($extension));
        $extension = ltrim($extension, '.');

        if ($extension === '' && $allowEmpty) {
            return '';
        }

        if (
            $extension === ''
            || preg_match('/^[a-z0-9]{1,20}$/', $extension) !== 1
        ) {
            throw new RuntimeException('File extension is invalid.');
        }

        return $extension;
    }
}
