<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Controllers;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $this->assertDiskAllowed($disk);

        $this->authorizeAccess(
            $request,
            $this->resolveFolderRule($disk, $path),
        );

        return $this->stream($disk, $path);
    }

    public function model(
        Request $request,
        string $resource,
        string $key,
        string $field,
    ): StreamedResponse {
        $resources = (array) config(
            'laravel-infrastructure.assets.resources',
            [],
        );
        $modelClass = $resources[$resource] ?? null;

        abort_unless(
            is_string($modelClass)
            && class_exists($modelClass)
            && is_subclass_of($modelClass, Model::class),
            404,
        );

        /** @var Model $prototype */
        $prototype = new $modelClass;

        abort_unless(
            method_exists($prototype, 'configuredFileAttributes'),
            404,
        );

        /** @var Model|null $model */
        $model = $prototype->newQuery()->find($key);

        abort_unless($model instanceof Model, 404);

        $configured = $model->configuredFileAttributes();
        $options = $configured[$field] ?? null;

        abort_unless(is_array($options), 404);

        $disk = (string) (
            $options['disk']
            ?? config(
                'laravel-infrastructure.files.disk',
                'public',
            )
        );
        $path = $model->getAttribute($field);

        abort_unless(
            is_string($path)
            && trim($path) !== '',
            404,
        );

        $this->assertDiskAllowed($disk);

        $rule = array_replace(
            $this->resolveFolderRule($disk, $path),
            is_array($options['access'] ?? null)
                ? $options['access']
                : [],
        );

        $this->authorizeAccess($request, $rule);

        return $this->stream($disk, $path);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function authorizeAccess(
        Request $request,
        array $rule,
    ): void {
        abort_if(
            (bool) ($rule['enabled'] ?? true) === false,
            404,
        );

        $signed = array_key_exists('signed', $rule)
            ? (bool) $rule['signed']
            : (bool) config(
                'laravel-infrastructure.assets.signed',
                true,
            );

        if ($signed && ! $request->hasValidSignature()) {
            abort(403);
        }

        $guard = $rule['guard'] ?? null;

        if (is_string($guard) && trim($guard) !== '') {
            abort_if(
                Auth::guard($guard)->guest(),
                401,
            );
        }
    }

    private function assertDiskAllowed(string $disk): void
    {
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
    }

    private function stream(
        string $disk,
        string $path,
    ): StreamedResponse {
        abort_unless(
            $this->files->exists($path, $disk),
            404,
        );

        return $this->filesystems
            ->disk($disk)
            ->response($path);
    }

    /**
     * Rules merge from "*" to parent folders to the most-specific folder.
     *
     * @return array<string, mixed>
     */
    private function resolveFolderRule(
        string $disk,
        string $path,
    ): array {
        $rules = (array) config(
            "laravel-infrastructure.assets.folder_access.{$disk}",
            [],
        );

        $path = trim(str_replace('\\', '/', $path), '/');
        $matches = [];

        foreach ($rules as $folder => $rule) {
            if (! is_string($folder) || ! is_array($rule)) {
                continue;
            }

            $folder = trim(str_replace('\\', '/', $folder), '/');

            if ($folder === '*' || $folder === '') {
                $matches[] = [
                    'folder' => '',
                    'rule' => $rule,
                ];

                continue;
            }

            if (
                $path === $folder
                || str_starts_with($path, $folder.'/')
            ) {
                $matches[] = [
                    'folder' => $folder,
                    'rule' => $rule,
                ];
            }
        }

        usort(
            $matches,
            static fn (array $left, array $right): int =>
                strlen($left['folder'])
                <=> strlen($right['folder']),
        );

        $resolved = [];

        foreach ($matches as $match) {
            $resolved = array_replace(
                $resolved,
                $match['rule'],
            );
        }

        return $resolved;
    }
}
