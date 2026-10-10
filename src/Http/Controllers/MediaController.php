<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Controllers;

use Ak279642\LaravelInfrastructure\Files\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MediaController
{
    public function publicFile(Request $request, string $alias, string $path): Response
    {
        $disk = MediaUrl::aliases()[$alias] ?? null;
        if ($disk === null || ! MediaUrl::safePath($path) || ! $this->publicExtension($path)) {
            return $this->errorImage(404);
        }
        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return $this->errorImage(404);
            }
            $response = $storage->response($path);
            $response->headers->set('Cache-Control', 'public, max-age=86400');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            return $response;
        } catch (Throwable) {
            return $this->errorImage(404);
        }
    }

    public function privateFile(Request $request, string $token): Response
    {
        if (! $request->hasValidSignature() || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            return $this->errorImage(403);
        }

        try {
            $encoded = strtr($token, '-_', '+/');
            $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
            $encrypted = base64_decode($encoded, true);
            if (! is_string($encrypted)) {
                return $this->errorImage(403);
            }
            $data = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            $class = $data['model'] ?? null;
            $field = $data['field'] ?? null;
            $key = $data['key'] ?? null;
            $originalPath = $data['path'] ?? null;
            if (! is_string($class) || ! is_subclass_of($class, Model::class)
                || ! is_string($field) || ! is_string($key) || $key === ''
                || ! is_string($originalPath) || ! MediaUrl::safePath($originalPath)) {
                return $this->errorImage(404);
            }
            /** @var Model|null $model */
            $model = $class::query()->find($key);
            if ($model === null || ! method_exists($model, 'configuredFileAttributes')) {
                return $this->errorImage(404);
            }
            $options = $model->configuredFileAttributes()[$field] ?? null;
            if (! is_array($options) || ($options['access']['enabled'] ?? true) === false
                || ($model->getAttributes()[$field] ?? null) !== $originalPath) {
                return $this->errorImage(403);
            }
            $disk = (string) ($options['disk'] ?? config('laravel-infrastructure.files.disk', 'public'));
            $access = is_array($options['access'] ?? null) ? $options['access'] : [];
            $guards = $access['guard'] ?? [];
            $guards = is_string($guards) ? [$guards] : (is_array($guards) ? $guards : []);
            $authenticated = false;

            foreach ($guards as $guard) {
                if (is_string($guard) && $guard !== '' && Auth::guard($guard)->check()) {
                    $authenticated = true;
                    break;
                }
            }
            $hasOwnerHook = method_exists($model, 'authorizesAssetField');
            if ((! $authenticated && ! $hasOwnerHook)
                || ($hasOwnerHook && ! $model->authorizesAssetField($field))) {
                return $this->errorImage(403);
            }
            $storage = Storage::disk($disk);
            if (! $storage->exists($originalPath)) {
                return $this->errorImage(404);
            }
            $response = $storage->response($originalPath);
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'image/')) {
                $response->headers->set('Content-Disposition', 'attachment');
            }
            return $response;
        } catch (Throwable) {
            return $this->errorImage(403);
        }
    }

    public function missing(): Response
    {
        return $this->errorImage(404);
    }

    private function publicExtension(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, [
            'webp', 'png', 'jpg', 'jpeg', 'gif', 'avif', 'ico',
            'woff', 'woff2', 'ttf', 'otf', 'pdf', 'apk', 'zip',
        ], true);
    }

    private function errorImage(int $status): Response
    {
        $configured = config("laravel-infrastructure.assets.error_images.{$status}");
        $default = dirname(__DIR__, 3).'/resources/images/'.
            ($status === 403 ? 'file-access-denied.webp' : 'file-not-found.webp');
        $path = is_string($configured) && is_file($configured) && is_readable($configured)
            ? $configured : $default;
        $response = response()->file($path);
        $response->headers->set('X-Asset-Error-Status', (string) $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setStatusCode(
            config('laravel-infrastructure.assets.render_error_images', true) ? 200 : $status,
        );
        return $response;
    }
}
