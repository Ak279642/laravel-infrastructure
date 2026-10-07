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
        $directory = trim(
            $directory ?? (string) config('laravel-infrastructure.files.directory', 'uploads'),
            '/',
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

    private function normalizeFilename(
        UploadedFile $file,
        ?string $filename,
    ): string {
        $extension = strtolower($file->getClientOriginalExtension());

        if (is_string($filename) && trim($filename) !== '') {
            $filename = basename(str_replace('\\', '/', trim($filename)));

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
