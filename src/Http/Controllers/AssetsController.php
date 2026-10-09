<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Controllers;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AssetsController
{
    public function __construct(
        private readonly FileStorage $files,
        private readonly FilesystemFactory $filesystems,
    ) {}

    public function __invoke(Request $request, string $disk, string $path): BinaryFileResponse|StreamedResponse
    {
        try {
            $this->assertDiskAllowed($disk);
            $this->assertGenericPathAllowed($disk, $path);
            $rule = $this->resolveFolderRule($disk, $path);
            $this->authorizeAccess($request, $rule);

            return $this->stream($disk, $path, $request, $rule);
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($this->assetErrorStatus($exception));
        }
    }

    /**
     * Optional legacy /uploads/{file} alias; its access rules and response
     * handling are identical to the package's generic disk asset endpoint.
     */
    public function upload(Request $request, string $file): BinaryFileResponse|StreamedResponse
    {
        $disk = (string) config('laravel-infrastructure.assets.legacy_uploads.disk', 'public');

        return $this($request, $disk, $file);
    }

    /** Always return an actual 404 image for unmatched asset URLs. */
    public function notFound(): BinaryFileResponse
    {
        return $this->errorResponse(404);
    }

    public function model(
        Request $request,
        string $resource,
        string $key,
        string $field,
        string $extension,
    ): BinaryFileResponse|StreamedResponse {
        try {
            return $this->serveModel($request, $resource, $key, $field, $extension);
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($this->assetErrorStatus($exception));
        }
    }

    public function modelField(
        Request $request,
        string $resource,
        string $key,
        string $attribute,
        string $field,
        string $extension,
    ): BinaryFileResponse|StreamedResponse {
        try {
            return $this->serveModel($request, $resource, $key, $field, $extension, $attribute);
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($this->assetErrorStatus($exception));
        }
    }

    private function serveModel(
        Request $request,
        string $resource,
        string $key,
        string $field,
        string $extension,
        ?string $requestedAttribute = null,
    ): BinaryFileResponse|StreamedResponse {
        $resources = (array) config('laravel-infrastructure.assets.resources', []);
        $modelClass = $resources[$resource] ?? null;

        abort_unless(
            is_string($modelClass)
            && class_exists($modelClass)
            && is_subclass_of($modelClass, Model::class),
            404,
        );

        /** @var Model $prototype */
        $prototype = new $modelClass;
        abort_unless(method_exists($prototype, 'configuredFileAttributes'), 404);

        /** @var Model|null $model */
        $model = $prototype->newQuery()->find($key);
        abort_unless($model instanceof Model, 404);

        $configured = $model->configuredFileAttributes();
        $requestedAttribute ??= $request->query('attribute');
        if (is_string($requestedAttribute) && $requestedAttribute !== '') {
            // The attribute is selected explicitly by getFileUrl(). Never
            // allow access to fields outside model file configuration.
            abort_unless(array_key_exists($requestedAttribute, $configured), 404);
            $column = $requestedAttribute;
            $options = $configured[$column];
            $path = $model->getAttribute($column);
            abort_unless(is_string($path) && trim($path) !== '', 404);
            $disk = (string) ($options['disk'] ?? config('laravel-infrastructure.files.disk', 'public'));
            $this->assertDiskAllowed($disk);
            $rule = array_replace(
                $this->resolveFolderRule($disk, $path),
                is_array($options['access'] ?? null) ? $options['access'] : [],
            );
            $this->authorizeAccess($request, $rule);
            if (method_exists($model, 'authorizesAssetField')) {
                abort_unless($model->authorizesAssetField($column), 403);
                $rule['cache_public'] = false;
            }
            return $this->stream($disk, $path, $request, $rule);
        }

        $matches = [];

        foreach ($configured as $column => $candidate) {
            $candidatePath = $model->getAttribute($column);

            if (! is_string($candidatePath) || trim($candidatePath) === '') {
                continue;
            }

            if (
                $model->infrastructureAssetFileName($column, $candidatePath) === $field
                && strtolower((string) pathinfo($candidatePath, PATHINFO_EXTENSION)) === strtolower($extension)
            ) {
                $matches[] = $column;
            }
        }

        // Ambiguous names never resolve to an arbitrary model field.
        abort_unless(count($matches) === 1, 404);
        $column = $matches[0];
        $options = $configured[$column];
        $disk = (string) ($options['disk'] ?? config('laravel-infrastructure.files.disk', 'public'));
        $path = $model->getAttribute($column);

        abort_unless(is_string($path) && trim($path) !== '', 404);
        $actualExtension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $actualExtension = $actualExtension === '' ? 'bin' : $actualExtension;
        abort_unless(hash_equals($actualExtension, strtolower($extension)), 404);

        $this->assertDiskAllowed($disk);
        $rule = array_replace(
            $this->resolveFolderRule($disk, $path),
            is_array($options['access'] ?? null) ? $options['access'] : [],
        );
        $this->authorizeAccess($request, $rule);

        // An optional model-level authorization hook supports ownership and
        // role-specific checks without a backend controller or middleware.
        if (method_exists($model, 'authorizesAssetField')) {
            abort_unless($model->authorizesAssetField($column), 403);
            // Authorization may depend on the current user: never cache publicly.
            $rule['cache_public'] = false;
        }

        return $this->stream($disk, $path, $request, $rule);
    }

    /**
     * Return an actual image body with the correct HTTP status. The configured
     * path must point to a trusted, readable local image file. Invalid overrides
     * fall back to the package's built-in artwork rather than an HTML page.
     */
    public function errorResponse(int $status): BinaryFileResponse
    {
        $status = $status === 403 ? 403 : 404;
        $configured = config("laravel-infrastructure.assets.error_images.{$status}");
        $default = dirname(__DIR__, 3).'/resources/images/'.(
            $status === 403 ? 'file-access-denied.webp' : 'file-not-found.webp'
        );

        $validExtensions = ['png', 'webp', 'jpg', 'jpeg', 'gif'];
        $validOverride = is_string($configured)
            && is_file($configured)
            && is_readable($configured)
            && in_array(strtolower((string) pathinfo($configured, PATHINFO_EXTENSION)), $validExtensions, true);
        $path = $validOverride ? $configured : $default;

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Missing package asset error image for HTTP {$status}: {$path}");
        }

        $mime = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'png' => 'image/png',
            default => 'image/png',
        };

        $response = response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // A browser navigating directly to HTTP 404/403 can display its own
        // error page instead of the returned WebP body. Serve placeholder
        // artwork with 200 by default so <img> and direct links both render.
        // The actual failure reason remains available in a dedicated header.
        $response->headers->set('X-Asset-Error-Status', (string) $status);
        $response->setStatusCode(
            (bool) config('laravel-infrastructure.assets.render_error_images', true)
                ? 200
                : $status,
        );
        // BinaryFileResponse may mark files public while preparing headers.
        // Error images must never be stored by shared caches.
        $response->headers->remove('Cache-Control');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function assetErrorStatus(HttpExceptionInterface $exception): int
    {
        if (in_array($exception->getStatusCode(), [401, 403], true)) {
            return 403;
        }
        if ($exception->getStatusCode() === 404) {
            return 404;
        }

        throw $exception;
    }

    /**
     * Generic disk URLs must not bypass model ACLs by guessing storage paths.
     * Optional disk-specific path patterns allow static assets and downloads.
     */
    private function assertGenericPathAllowed(string $disk, string $path): void
    {
        $patterns = config("laravel-infrastructure.assets.generic_path_patterns.{$disk}");
        if ($patterns === null) {
            return;
        }

        abort_unless(is_array($patterns) && $patterns !== [], 403);

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $path) === 1) {
                return;
            }
        }

        abort(403);
    }

    /** @param array<string, mixed> $rule */
    private function authorizeAccess(Request $request, array $rule): void
    {
        abort_if((bool) ($rule['enabled'] ?? true) === false, 403);

        $signed = array_key_exists('signed', $rule)
            ? (bool) $rule['signed']
            : (bool) config('laravel-infrastructure.assets.signed', true);
        abort_if($signed && ! $request->hasValidSignature(), 403);

        $guard = $rule['guard'] ?? null;
        $guards = is_string($guard) ? [$guard] : (is_array($guard) ? $guard : []);
        $guards = array_values(array_filter(
            $guards,
            static fn (mixed $name): bool => is_string($name) && trim($name) !== '',
        ));

        foreach ($guards as $guardName) {
            try {
                if (Auth::guard(trim($guardName))->check()) {
                    return;
                }
            } catch (InvalidArgumentException) {
                // Unknown guards deny access rather than exposing an HTML 500.
                continue;
            }
        }

        abort_if($guards !== [], 403);
    }

    private function assertDiskAllowed(string $disk): void
    {
        $allowedDisks = array_values(array_filter(
            (array) config('laravel-infrastructure.assets.allowed_disks', ['public']),
            'is_string',
        ));

        abort_unless(in_array($disk, $allowedDisks, true), 404);
    }

    /** @param array<string, mixed> $rule */
    private function stream(
        string $disk,
        string $path,
        Request $request,
        array $rule,
    ): BinaryFileResponse|StreamedResponse {
        try {
            if (! $this->files->exists($path, $disk)) {
                return $this->errorResponse(404);
            }
        } catch (RuntimeException|InvalidArgumentException) {
            return $this->errorResponse(404);
        }

        try {
            $response = $this->filesystems->disk($disk)->response($path);
        } catch (RuntimeException|InvalidArgumentException) {
            // A file may disappear between the existence check and streaming.
            return $this->errorResponse(404);
        }

        // Image caching is explicit: no caching for other file types.
        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! str_starts_with($contentType, 'image/')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        }

        // Public browser/CDN caching is independent of Laravel's cache store.
        // Never persist guarded, signed, or model-authorized responses.
        $signed = array_key_exists('signed', $rule)
            ? (bool) $rule['signed']
            : (bool) config('laravel-infrastructure.assets.signed', true);
        $guard = $rule['guard'] ?? null;
        $hasGuard = is_string($guard) ? trim($guard) !== '' : (is_array($guard) && $guard !== []);
        $isPrivate = $signed || $hasGuard || ($rule['cache_public'] ?? true) === false;

        if ($isPrivate) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

            return $response;
        }

        // Public images get browser/CDN caching and a metadata-based ETag.
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        // Weak ETags use metadata only, never read the whole remote image into RAM.
        try {
            $filesystem = $this->filesystems->disk($disk);
            $stamp = $filesystem->lastModified($path);
            $size = $filesystem->size($path);
            $etag = 'W/"'.substr(hash('sha256', $disk.'|'.$path.'|'.$stamp.'|'.$size), 0, 32).'"';
            $response->headers->set('ETag', $etag);
            // Conditional requests are evaluated only after all access checks.
            $response->isNotModified($request);
        } catch (Throwable) {
            // Some remote disks cannot provide file metadata.
        }

        return $response;
    }

    /**
     * Rules merge from "*" to parent folders to the most-specific folder.
     * @return array<string, mixed>
     */
    private function resolveFolderRule(string $disk, string $path): array
    {
        $rules = (array) config("laravel-infrastructure.assets.folder_access.{$disk}", []);
        $path = trim(str_replace('\\', '/', $path), '/');
        $matches = [];

        foreach ($rules as $folder => $rule) {
            if (! is_string($folder) || ! is_array($rule)) {
                continue;
            }

            $folder = trim(str_replace('\\', '/', $folder), '/');
            if ($folder === '*' || $folder === '') {
                $matches[] = ['folder' => '', 'rule' => $rule];
                continue;
            }

            if ($path === $folder || str_starts_with($path, $folder.'/')) {
                $matches[] = ['folder' => $folder, 'rule' => $rule];
            }
        }

        usort(
            $matches,
            static fn (array $left, array $right): int => strlen($left['folder']) <=> strlen($right['folder']),
        );
        $resolved = [];
        foreach ($matches as $match) {
            $resolved = array_replace($resolved, $match['rule']);
        }

        return $resolved;
    }
}
