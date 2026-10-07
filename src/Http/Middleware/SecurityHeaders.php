<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ([
            'Server',
            'X-Powered-By',
            'X-Generator',
            'X-Runtime',
        ] as $header) {
            $response->headers->remove($header);
        }

        $response->headers->set(
            'X-Content-Type-Options',
            'nosniff',
        );
        $response->headers->set(
            'X-Frame-Options',
            'SAMEORIGIN',
        );
        $response->headers->set(
            'Referrer-Policy',
            'strict-origin-when-cross-origin',
        );

        return $response;
    }
}
