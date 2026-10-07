<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CorrelationId
{
    private const ATTRIBUTE = 'laravel_infrastructure_correlation_id';

    private function __construct() {}

    public static function resolve(?Request $request = null): ?string
    {
        $request ??= self::request();

        if (! $request) {
            return null;
        }

        $current = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($current) && $current !== '') {
            return $current;
        }

        $incoming = (string) $request->headers->get(
            self::headerName(),
            '',
        );

        if (
            (bool) config(
                'laravel-infrastructure.logging.accept_incoming_correlation_id',
                true,
            )
            && self::isValid($incoming)
        ) {
            $id = $incoming;
        } else {
            $id = (string) Str::uuid();
        }

        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }

    public static function current(): ?string
    {
        return self::resolve();
    }

    public static function headerName(): string
    {
        $header = trim((string) config(
            'laravel-infrastructure.logging.correlation_header',
            'X-Request-ID',
        ));

        return $header !== '' ? $header : 'X-Request-ID';
    }

    public static function set(Request $request, string $id): string
    {
        $id = self::isValid($id)
            ? $id
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }

    public static function isValid(string $id): bool
    {
        return $id !== ''
            && strlen($id) <= 128
            && preg_match('/^[A-Za-z0-9._:-]+$/', $id) === 1;
    }

    private static function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        return request();
    }
}
