<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Middleware;

use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectSensitivePaths
{
    private const BLOCKED_DIRECTORIES = [
        'app',
        'bootstrap',
        'config',
        'database',
        'resources',
        'routes',
        'tests',
        'vendor',
    ];

    private const BLOCKED_FILES = [
        '.env',
        '.git',
        '.gitattributes',
        '.gitignore',
        '.htaccess',
        '.htpasswd',
        'artisan',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'phpunit.xml',
        'phpunit.xml.dist',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isSensitivePath($request)) {
            CustomLog::warning(
                'Blocked sensitive path probe.',
                [
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ],
                LogDomain::API,
            );

            return $this->notFound($request);
        }

        return $next($request);
    }

    private function isSensitivePath(Request $request): bool
    {
        $rawPath = (string) parse_url(
            $request->getRequestUri(),
            PHP_URL_PATH,
        );

        $decodedPath = rawurldecode(rawurldecode($rawPath));
        $decodedPath = strtolower(
            str_replace('\\', '/', $decodedPath),
        );

        if (
            str_contains($decodedPath, "\0")
            || str_contains($decodedPath, '../')
            || str_contains($decodedPath, '/..')
        ) {
            return true;
        }

        $segments = array_values(array_filter(
            explode('/', trim($decodedPath, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        if (
            isset($segments[0])
            && in_array(
                $segments[0],
                self::BLOCKED_DIRECTORIES,
                true,
            )
        ) {
            return true;
        }

        foreach ($segments as $segment) {
            if (
                in_array($segment, self::BLOCKED_FILES, true)
                || str_starts_with($segment, '.env.')
            ) {
                return true;
            }
        }

        if (
            ($segments[0] ?? null) === 'storage'
            && isset($segments[1])
            && in_array(
                $segments[1],
                ['logs', 'framework', 'app'],
                true,
            )
        ) {
            return true;
        }

        return false;
    }

    private function notFound(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Not found.',
                'error_code' => 'NOT_FOUND',
            ], 404);
        }

        abort(404);
    }
}
