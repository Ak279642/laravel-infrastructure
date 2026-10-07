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

        $rule = array_replace(
            $this->resolveFolderRule($disk, $path),
            $this->resolveModelFileRule(
                $request,
                $disk,
                $path,
            ),
        );

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

        abort_unless(
            $this->files->exists($path, $disk),
            404,
        );

        return $this->filesystems
            ->disk($disk)
            ->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveModelFileRule(
        Request $request,
        string $disk,
        string $path,
    ): array {
        $modelClass = $request->query('model');
        $key = $request->query('key');
        $field = $request->query('field');

        if (
            ! is_string($modelClass)
            || ! is_string($key)
            || ! is_string($field)
            || $modelClass === ''
            || $key === ''
            || $field === ''
        ) {
            return [];
        }

        abort_unless(
            class_exists($modelClass)
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

        $configuredDisk = (string) (
            $options['disk']
            ?? config('laravel-infrastructure.files.disk', 'public')
        );
        $configuredPath = $model->getAttribute($field);

        abort_unless(
            $configuredDisk === $disk
            && is_string($configuredPath)
            && $configuredPath === $path,
            404,
        );

        return is_array($options['access'] ?? null)
            ? $options['access']
            : [];
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
