<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Controllers;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $user = $this->resolveUser($request, $rule);

        $this->authorizeRoles($user, $rule);
        $this->authorizePermissions($user, $rule);
        $this->authorizeAbility(
            $user,
            $disk,
            $path,
            $rule,
        );

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
     * Rules are merged from least-specific to most-specific.
     *
     * Example:
     *   public/*                  -> default for disk
     *   public/products          -> products override
     *   public/products/invoices -> most specific override
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

        // Backwards compatibility for the previous disk-level ability.
        if (! array_key_exists('ability', $resolved)) {
            $legacyAbility = config(
                "laravel-infrastructure.assets.disk_abilities.{$disk}",
            );

            if (
                is_string($legacyAbility)
                && $legacyAbility !== ''
            ) {
                $resolved['ability'] = $legacyAbility;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function resolveUser(
        Request $request,
        array $rule,
    ): ?Authenticatable {
        $guard = $rule['guard'] ?? null;

        if (is_string($guard) && $guard !== '') {
            $user = Auth::guard($guard)->user();

            abort_if($user === null, 401);

            return $user;
        }

        $requiresUser = $this->stringList(
            $rule['roles'] ?? [],
        ) !== []
            || $this->stringList(
                $rule['permissions'] ?? [],
            ) !== []
            || (
                is_string($rule['ability'] ?? null)
                && $rule['ability'] !== ''
            );

        $user = $request->user();

        if ($requiresUser) {
            abort_if($user === null, 401);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function authorizeRoles(
        ?Authenticatable $user,
        array $rule,
    ): void {
        $roles = $this->stringList(
            $rule['roles'] ?? [],
        );

        if ($roles === []) {
            return;
        }

        abort_if($user === null, 401);

        $allowed = false;

        if (method_exists($user, 'hasAnyRole')) {
            $allowed = (bool) $user->hasAnyRole($roles);
        } elseif (method_exists($user, 'hasRole')) {
            foreach ($roles as $role) {
                if ((bool) $user->hasRole($role)) {
                    $allowed = true;
                    break;
                }
            }
        } elseif (
            method_exists($user, 'getAttribute')
            && is_scalar($user->getAttribute('role'))
        ) {
            $allowed = in_array(
                (string) $user->getAttribute('role'),
                $roles,
                true,
            );
        }

        abort_unless($allowed, 403);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function authorizePermissions(
        ?Authenticatable $user,
        array $rule,
    ): void {
        $permissions = $this->stringList(
            $rule['permissions'] ?? [],
        );

        if ($permissions === []) {
            return;
        }

        abort_if($user === null, 401);

        foreach ($permissions as $permission) {
            abort_unless(
                Gate::forUser($user)->allows($permission),
                403,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function authorizeAbility(
        ?Authenticatable $user,
        string $disk,
        string $path,
        array $rule,
    ): void {
        $ability = $rule['ability'] ?? null;

        if (! is_string($ability) || $ability === '') {
            return;
        }

        if ($user !== null) {
            Gate::forUser($user)->authorize(
                $ability,
                [$disk, $path],
            );

            return;
        }

        Gate::authorize(
            $ability,
            [$disk, $path],
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($item): bool =>
                is_string($item)
                && trim($item) !== '',
        ));
    }
}
