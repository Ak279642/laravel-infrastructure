<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
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

        $path = $this->files
            ->disk($disk)
            ->putFileAs($directory, $file, $filename);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Unable to store uploaded file.');
        }

        return $path;
    }

    public function delete(?string $path, ?string $disk = null): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $path = $this->normalizeStoredPath($path);
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

        $disk = $disk ?: (string) config('laravel-infrastructure.files.disk', 'public');
        $filesystem = $this->files->disk($disk);

        if (! method_exists($filesystem, 'url')) {
            return null;
        }

        return $filesystem->url($path);
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
        $extension = strtolower(trim($file->getClientOriginalExtension()));

        if (
            $extension !== ''
            && preg_match('/^[a-z0-9]{1,20}$/', $extension) !== 1
        ) {
            throw new RuntimeException('Uploaded file extension is invalid.');
        }

        if (is_string($filename) && trim($filename) !== '') {
            if (
                str_contains($filename, "\0")
                || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
            ) {
                throw new RuntimeException('Filename contains unsafe characters.');
            }

            $filename = basename(str_replace('\\', '/', trim($filename)));

            if ($filename === '' || $filename === '.' || $filename === '..') {
                throw new RuntimeException('Filename is invalid.');
            }

            if ($extension !== '' && pathinfo($filename, PATHINFO_EXTENSION) === '') {
                $filename .= '.'.$extension;
            }

            return $filename;
        }

        $name = (string) Str::uuid();

        return $extension === ''
            ? $name
            : $name.'.'.$extension;
    }
}
