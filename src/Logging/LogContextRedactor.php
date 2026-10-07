<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use Throwable;

final class LogContextRedactor
{
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'set_cookie',
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        '_token',
        'csrf_token',
        'access_token',
        'refresh_token',
        'api_token',
        'api_key',
        'secret',
        'secret_key',
        'client_secret',
        'private_key',
        'jwt',
        'session',
        'signature',
    ];

    private function __construct() {}

    public static function redact(
        mixed $value,
        ?string $key = null,
        int $depth = 0,
    ): mixed {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '********';
        }

        if ($depth >= (int) config(
            'laravel-infrastructure.logging.max_depth',
            6,
        )) {
            return '[max-depth]';
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        if (! is_array($value)) {
            return is_object($value) && ! $value instanceof Throwable
                ? (method_exists($value, '__toString')
                    ? (string) $value
                    : $value::class)
                : $value;
        }

        $sanitized = [];
        $limit = max(
            1,
            (int) config(
                'laravel-infrastructure.logging.max_array_items',
                100,
            ),
        );

        foreach (
            array_slice($value, 0, $limit, true)
            as $itemKey => $itemValue
        ) {
            $sanitized[$itemKey] = self::redact(
                $itemValue,
                is_string($itemKey) ? $itemKey : null,
                $depth + 1,
            );
        }

        return $sanitized;
    }

    private static function redactString(string $value): string
    {
        $value = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i',
            '$1********',
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/((?:password|token|api[_-]?key|secret|client[_-]?secret)\s*[=:]\s*)[^\s,;]+/i',
            '$1********',
            $value,
        ) ?? $value;

        $max = max(
            1,
            (int) config(
                'laravel-infrastructure.logging.max_string_length',
                4096,
            ),
        );

        return strlen($value) > $max
            ? substr($value, 0, $max).'...[truncated]'
            : $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (
                $normalized === $sensitiveKey
                || str_ends_with($normalized, '_'.$sensitiveKey)
            ) {
                return true;
            }
        }

        return false;
    }
}
