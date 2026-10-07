<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Middleware;

use Ak279642\LaravelInfrastructure\Logging\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = CorrelationId::resolve($request);
        $response = $next($request);

        if ($id !== null) {
            $response->headers->set(CorrelationId::headerName(), $id);
        }

        return $response;
    }
}
