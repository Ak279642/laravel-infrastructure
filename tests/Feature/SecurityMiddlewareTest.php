<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Http\Middleware\RejectSensitivePaths;
use Ak279642\LaravelInfrastructure\Http\Middleware\SecurityHeaders;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityMiddlewareTest extends TestCase
{
    public function test_security_headers_remove_disclosure_headers_and_add_safe_defaults(): void
    {
        $request = Request::create('/health');

        $response = (new SecurityHeaders)->handle(
            $request,
            static function (): Response {
                $response = new Response('ok');
                $response->headers->set('Server', 'nginx');
                $response->headers->set('X-Powered-By', 'PHP');

                return $response;
            },
        );

        self::assertFalse($response->headers->has('Server'));
        self::assertFalse($response->headers->has('X-Powered-By'));
        self::assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options'),
        );
        self::assertSame(
            'SAMEORIGIN',
            $response->headers->get('X-Frame-Options'),
        );
        self::assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy'),
        );
    }

    public function test_sensitive_path_middleware_blocks_encoded_and_plain_probes(): void
    {
        $middleware = new RejectSensitivePaths;

        foreach ([
            '/.env',
            '/config/app.php',
            '/storage/logs/laravel.log',
            '/%252e%252e/.env',
        ] as $path) {
            $request = Request::create(
                $path,
                'GET',
                server: ['HTTP_ACCEPT' => 'application/json'],
            );

            $response = $middleware->handle(
                $request,
                static fn (): Response => new Response('allowed'),
            );

            self::assertSame(404, $response->getStatusCode());
        }
    }

    public function test_sensitive_path_middleware_allows_normal_application_paths(): void
    {
        $request = Request::create('/api/customers');

        $response = (new RejectSensitivePaths)->handle(
            $request,
            static fn (): Response => new Response('allowed', 200),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('allowed', $response->getContent());
    }

    public function test_package_registers_middleware_aliases(): void
    {
        $router = $this->app['router'];
        $middleware = $router->getMiddleware();

        self::assertSame(
            SecurityHeaders::class,
            $middleware['infrastructure.security-headers'] ?? null,
        );
        self::assertSame(
            RejectSensitivePaths::class,
            $middleware['infrastructure.reject-sensitive-paths'] ?? null,
        );
    }
}
