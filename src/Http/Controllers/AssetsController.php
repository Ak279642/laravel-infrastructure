<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Controllers;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssetsController
{
    public function __construct(
        private readonly FileStorage $files,
        private readonly FilesystemFactory $filesystems,
    ) {}

    public function __invoke(
        Request $request,
        string $disk,
        string $path,
    ): StreamedResponse {
        $allowedDisks = array_values(array_filter(
            (array) config(
                'laravel-infrastructure.assets.allowed_disks',
                ['public'],
            ),
            'is_string',
        ));

        abort_unless(
            in_array($disk, $allowedDisks, true),
            404,
        );

        if (
            (bool) config(
                'laravel-infrastructure.assets.signed',
                true,
            )
            && ! $request->hasValidSignature()
        ) {
            abort(403);
        }

        $ability = config(
            "laravel-infrastructure.assets.disk_abilities.{$disk}",
        );

        if (is_string($ability) && $ability !== '') {
            Gate::authorize($ability, [$disk, $path]);
        }

        abort_unless(
            $this->files->exists($path, $disk),
            404,
        );

        return $this->filesystems
            ->disk($disk)
            ->response($path);
    }
}
