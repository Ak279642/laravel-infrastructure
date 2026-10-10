<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class MediaUrl
{
    /** @return array<string, string> URL alias => explicitly public Laravel disk */
    public static function aliases(): array
    {
        $disks = (array) config('laravel-infrastructure.assets.public_disks', ['public']);
        $configured = (array) config('laravel-infrastructure.assets.disk_aliases', []);
        $aliases = [];

        foreach ($disks as $disk) {
            if (! is_string($disk) || preg_match('/^[a-zA-Z0-9_-]+$/D', $disk) !== 1) {
                throw new RuntimeException('Invalid public media disk.');
            }
            $names = array_keys($configured, $disk, true);
            if ($names === []) {
                $names = [$disk];
            }
            foreach ($names as $alias) {
                if (! is_string($alias) || preg_match('/^[a-zA-Z0-9_-]+$/D', $alias) !== 1
                    || in_array($alias, ['_infrastructure', 'private'], true)) {
                    throw new RuntimeException('Invalid media disk alias.');
                }
                $aliases[$alias] = $disk;
            }
        }

        foreach ($configured as $alias => $disk) {
            if (! in_array($disk, $disks, true)) {
                throw new RuntimeException('Media aliases cannot expose non-public disks.');
            }
        }

        return $aliases;
    }

    public static function safePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || str_starts_with($segment, '.') || preg_match('/^[A-Za-z0-9_.-]+$/D', $segment) !== 1) {
                return false;
            }
        }
        return true;
    }

    public function forModel(
        Model $model,
        string $fieldOrPath,
        ?string $seoName = null,
        ?DateTimeInterface $expiration = null,
    ): string {
        $fields = method_exists($model, 'configuredFileAttributes')
            ? $model->configuredFileAttributes()
            : [];
        $isField = array_key_exists($fieldOrPath, $fields);
        $options = $isField ? $fields[$fieldOrPath] : [];
        // getRawOriginal/getAttributes never lazy-load relationships or query the database.
        $path = $isField ? ($model->getAttributes()[$fieldOrPath] ?? null) : $fieldOrPath;

        if (! is_string($path) || ! self::safePath($path)) {
            return route('laravel-infrastructure.assets.missing');
        }

        if ($isField && ($options['access']['enabled'] ?? true) === false) {
            return route('laravel-infrastructure.assets.missing');
        }
        $disk = (string) ($options['disk'] ?? config('laravel-infrastructure.files.disk', 'public'));
        $access = is_array($options['access'] ?? null) ? $options['access'] : [];
        $public = in_array($disk, self::aliases(), true)
            && ! ($access['signed'] ?? false)
            && empty($access['guard'])
            && ! ($isField && method_exists($model, 'authorizesAssetField'));

        if (! $public) {
            if (in_array($disk, self::aliases(), true)) {
                throw new InvalidArgumentException('Protected file fields must use a private disk; public storage is directly accessible.');
            }
            if (! $isField || ! $model->exists || $model->getKey() === null) {
                throw new InvalidArgumentException('Protected file URLs require a saved model and configured file attribute.');
            }
            $payload = json_encode([
                'model' => $model::class,
                'key' => (string) $model->getKey(),
                'field' => $fieldOrPath,
                'path' => $path,
            ], JSON_THROW_ON_ERROR);
            $token = rtrim(strtr(base64_encode(Crypt::encryptString($payload)), '+/', '-_'), '=');

            return URL::temporarySignedRoute(
                'laravel-infrastructure.assets.private',
                $expiration ?? now()->addMinutes(max(1, (int) config('laravel-infrastructure.assets.url_ttl_minutes', 15))),
                ['token' => $token],
            );
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($seoName !== null && trim($seoName) !== '') {
            $suggested = Str::slug(pathinfo($seoName, PATHINFO_FILENAME)).'.'.$extension;
            if ($suggested !== basename($path)) {
                throw new InvalidArgumentException('SEO name must match the stored filename. Configure filename_from or filename during upload.');
            }
        }
        $alias = array_search($disk, self::aliases(), true);
        $attributes = $model->getAttributes();
        $version = substr(hash('sha256', $path.'|'.(string) ($attributes['updated_at'] ?? '')), 0, 12);

        return route('laravel-infrastructure.assets.public', [
            'alias' => $alias,
            'path' => $path,
            'v' => $version,
        ]);
    }
}
