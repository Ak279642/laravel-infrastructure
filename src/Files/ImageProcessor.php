<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;
use Throwable;

final class ImageProcessor
{
    public function __construct(
        private readonly FileStorage $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function store(
        UploadedFile $file,
        string $directory,
        string $disk,
        ?string $filename,
        array $options = [],
    ): string {
        $settings = array_replace(
            (array) config(
                'laravel-infrastructure.files.image',
                [],
            ),
            $options,
        );

        $driver = strtolower(
            (string) ($settings['driver'] ?? 'gd'),
        );
        $format = strtolower(
            (string) ($settings['format'] ?? 'webp'),
        );
        $resize = strtolower(
            (string) ($settings['resize'] ?? 'scale_down'),
        );
        $width = $this->dimension(
            $settings['width'] ?? null,
            'width',
        );
        $height = $this->dimension(
            $settings['height'] ?? null,
            'height',
        );
        $quality = max(
            0,
            min(100, (int) ($settings['quality'] ?? 80)),
        );
        $position = trim(
            (string) ($settings['position'] ?? 'center'),
        ) ?: 'center';

        try {
            $manager = $this->manager($driver);
            $realPath = $file->getRealPath();

            if (! is_string($realPath) || $realPath === '') {
                throw new RuntimeException(
                    'Unable to locate uploaded image contents.',
                );
            }

            $contents = file_get_contents($realPath);

            if (! is_string($contents) || $contents === '') {
                throw new RuntimeException(
                    'Unable to read uploaded image contents.',
                );
            }

            $image = method_exists(
                $manager,
                'decodeBinary',
            )
                ? $manager->decodeBinary($contents)
                : $manager->read($contents);

            $this->resize(
                $image,
                $resize,
                $width,
                $height,
                $position,
            );

            [$encoded, $extension] = match ($format) {
                'webp' => [
                    $image->encode(
                        new WebpEncoder(
                            quality: $quality,
                        ),
                    ),
                    'webp',
                ],
                'original' => [
                    $image->encode(
                        new AutoEncoder(
                            quality: $quality,
                        ),
                    ),
                    $this->originalExtension($file),
                ],
                default => throw new RuntimeException(
                    "Unsupported image output format [{$format}]. ".
                    'Supported formats: webp, original.',
                ),
            };

            return $this->storage->storeContents(
                contents: (string) $encoded,
                extension: $extension,
                directory: $directory,
                disk: $disk,
                filename: $this->outputFilename(
                    $filename,
                    $extension,
                ),
            );
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to process uploaded image: '.
                $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function manager(string $driver): ImageManager
    {
        return match ($driver) {
            'gd' => $this->gdManager(),
            'imagick' => $this->imagickManager(),
            default => throw new RuntimeException(
                "Unsupported image driver [{$driver}]. ".
                'Supported drivers: gd, imagick.',
            ),
        };
    }

    private function gdManager(): ImageManager
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException(
                'Image processing with the GD driver requires ext-gd.',
            );
        }

        return new ImageManager(
            GdDriver::class,
        );
    }

    private function imagickManager(): ImageManager
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException(
                'Image processing with the Imagick driver requires ext-imagick.',
            );
        }

        return new ImageManager(
            ImagickDriver::class,
        );
    }

    private function resize(
        ImageInterface $image,
        string $mode,
        ?int $width,
        ?int $height,
        string $position,
    ): void {
        if ($mode === 'none') {
            return;
        }

        if ($width === null && $height === null) {
            throw new RuntimeException(
                'Image resize width or height is required.',
            );
        }

        match ($mode) {
            'scale' => $image->scale(
                width: $width,
                height: $height,
            ),
            'scale_down' => $image->scaleDown(
                width: $width,
                height: $height,
            ),
            'resize' => $image->resize(
                width: $width,
                height: $height,
            ),
            'resize_down' => $image->resizeDown(
                width: $width,
                height: $height,
            ),
            'cover' => $this->cover(
                $image,
                $width,
                $height,
                $position,
                false,
            ),
            'cover_down' => $this->cover(
                $image,
                $width,
                $height,
                $position,
                true,
            ),
            default => throw new RuntimeException(
                "Unsupported image resize mode [{$mode}].",
            ),
        };
    }

    private function cover(
        ImageInterface $image,
        ?int $width,
        ?int $height,
        string $position,
        bool $down,
    ): void {
        if ($width === null || $height === null) {
            throw new RuntimeException(
                'Image cover resize requires both width and height.',
            );
        }

        if ($down) {
            $image->coverDown(
                $width,
                $height,
                $position,
            );

            return;
        }

        $image->cover(
            $width,
            $height,
            $position,
        );
    }

    private function dimension(
        mixed $value,
        string $name,
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            ! is_int($value)
            && ! (
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            throw new RuntimeException(
                "Image {$name} must be a positive integer or null.",
            );
        }

        $value = (int) $value;

        if ($value < 1) {
            throw new RuntimeException(
                "Image {$name} must be greater than zero.",
            );
        }

        return $value;
    }

    private function originalExtension(
        UploadedFile $file,
    ): string {
        $extension = strtolower(
            trim(
                (string) (
                    $file->guessExtension()
                    ?: $file->getClientOriginalExtension()
                ),
            ),
        );

        return $extension === 'jpeg'
            ? 'jpg'
            : $extension;
    }

    private function outputFilename(
        ?string $filename,
        string $extension,
    ): ?string {
        if (! is_string($filename) || trim($filename) === '') {
            return null;
        }

        $filename = trim($filename);
        $current = (string) pathinfo(
            $filename,
            PATHINFO_EXTENSION,
        );

        if ($current === '') {
            return $filename;
        }

        return substr(
            $filename,
            0,
            -strlen($current),
        ).$extension;
    }
}
