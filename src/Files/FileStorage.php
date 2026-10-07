<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Ak279642\LaravelInfrastructure\Exceptions\InvalidFilePathException;
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
        $disk = $this->resolveDisk($disk);
        $directory = $this->normalizeDirectory(
            $directory ?? (string) config(
                'laravel-infrastructure.files.directory',
                'uploads',
            ),
        );

        $filename = $this->normalizeFilename(
            $file,
            $filename,
        );

        $path = $this->files
            ->disk($disk)
            ->putFileAs(
                $directory,
                $file,
                $filename,
            );

        if (
            ! is_string($path)
            || $path === ''
        ) {
            throw new RuntimeException(
                'Unable to store uploaded file.',
            );
        }

        return $this->normalizeStoredPath($path);
    }

    public function delete(
        ?string $path,
        ?string $disk = null,
    ): bool {
        if (
            ! is_string($path)
            || trim($path) === ''
        ) {
            return false;
        }

        $path = $this->normalizeStoredPath($path);
        $filesystem = $this->files->disk(
            $this->resolveDisk($disk),
        );

        if (! $filesystem->exists($path)) {
            return false;
        }

        return $filesystem->delete($path);
    }

    public function exists(
        ?string $path,
        ?string $disk = null,
    ): bool {
        if (
            ! is_string($path)
            || trim($path) === ''
        ) {
            return false;
        }

        return $this->files
            ->disk($this->resolveDisk($disk))
            ->exists(
                $this->normalizeStoredPath($path),
            );
    }

    public function url(
        ?string $path,
        ?string $disk = null,
    ): ?string {
        if (
            ! is_string($path)
            || trim($path) === ''
        ) {
            return null;
        }

        $filesystem = $this->files->disk(
            $this->resolveDisk($disk),
        );

        if (! method_exists($filesystem, 'url')) {
            return null;
        }

        return $filesystem->url(
            $this->normalizeStoredPath($path),
        );
    }

    private function resolveDisk(?string $disk): string
    {
        $disk = trim(
            $disk
                ?: (string) config(
                    'laravel-infrastructure.files.disk',
                    'public',
                ),
        );

        if ($disk === '') {
            throw new InvalidFilePathException(
                'A non-empty filesystem disk is required.',
            );
        }

        return $disk;
    }

    private function normalizeDirectory(string $directory): string
    {
        $directory = trim(
            str_replace('\\', '/', $directory),
            '/',
        );

        if ($directory === '') {
            return '';
        }

        $this->assertSafeRelativePath(
            $directory,
            'directory',
        );

        return $directory;
    }

    private function normalizeStoredPath(string $path): string
    {
        $path = trim(
            str_replace('\\', '/', $path),
        );

        $this->assertSafeRelativePath(
            $path,
            'path',
        );

        return ltrim($path, '/');
    }

    private function normalizeFilename(
        UploadedFile $file,
        ?string $filename,
    ): string {
        $extension = strtolower(
            $file->getClientOriginalExtension(),
        );

        if (
            $extension !== ''
            && ! preg_match('/^[a-z0-9]{1,16}$/', $extension)
        ) {
            $extension = '';
        }

        if (
            is_string($filename)
            && trim($filename) !== ''
        ) {
            $filename = trim($filename);

            if (
                str_contains($filename, '/')
                || str_contains($filename, '\\')
                || str_contains($filename, "\0")
                || preg_match('/[\x00-\x1F\x7F]/', $filename)
            ) {
                throw new InvalidFilePathException(
                    'Custom filenames must be plain filenames without path separators or control characters.',
                );
            }

            if (in_array($filename, ['.', '..'], true)) {
                throw new InvalidFilePathException(
                    'Invalid custom filename.',
                );
            }

            if (
                $extension !== ''
                && pathinfo(
                    $filename,
                    PATHINFO_EXTENSION,
                ) === ''
            ) {
                $filename .= '.'.$extension;
            }

            return $filename;
        }

        $name = (string) Str::uuid();

        return $extension === ''
            ? $name
            : $name.'.'.$extension;
    }

    private function assertSafeRelativePath(
        string $path,
        string $label,
    ): void {
        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $path)
            || str_starts_with($path, '/')
            || preg_match('/^[a-zA-Z]:\//', $path)
            || str_contains($path, '://')
        ) {
            throw new InvalidFilePathException(
                "Invalid file {$label}.",
            );
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new InvalidFilePathException(
                    "File {$label} traversal is not allowed.",
                );
            }
        }
    }
}
