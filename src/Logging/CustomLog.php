<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use Illuminate\Http\Request;
use Throwable;

final class CustomLog
{
    private const SENSITIVE_KEYS = [
        'authorization', 'cookie', 'password', 'password_confirmation',
        'current_password', 'new_password', 'token', 'access_token',
        'refresh_token', 'api_token', 'api_key', 'secret', 'client_secret',
    ];

    public static function debug(string $message, array $context = [], LogDomain|string $domain = LogDomain::APPLICATION): void
    {
        self::write('debug', $message, $context, $domain);
    }

    public static function info(string $message, array $context = [], LogDomain|string $domain = LogDomain::APPLICATION): void
    {
        self::write('info', $message, $context, $domain);
    }

    public static function warning(string $message, array $context = [], LogDomain|string $domain = LogDomain::APPLICATION): void
    {
        self::write('warning', $message, $context, $domain);
    }

    public static function error(string $message, array $context = [], LogDomain|string $domain = LogDomain::ERRORS): void
    {
        self::write('error', $message, $context, $domain);
    }

    public static function exception(
        Throwable $exception,
        array $context = [],
        ?string $message = null,
        string $level = 'error',
        LogDomain|string $domain = LogDomain::ERRORS,
    ): void {
        $context['exception'] = self::exceptionContext($exception);
        self::write($level, $message ?? $exception::class.': '.$exception->getMessage(), $context, $domain);
    }

    public static function enabled(LogDomain|string $domain = LogDomain::APPLICATION): bool
    {
        if (! (bool) config('laravel-infrastructure.logging.enabled', true)) {
            return false;
        }

        return (bool) config('laravel-infrastructure.logging.domain_enabled.'.self::domainName($domain), true);
    }

    public static function exceptionContext(Throwable $exception): array
    {
        $context = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ((bool) config('laravel-infrastructure.logging.exception_trace_enabled', false)) {
            $context['trace'] = $exception->getTraceAsString();
        }

        return self::sanitize($context);
    }

    public static function businessException(Throwable $exception, array $context = []): void
    {
        self::exception($exception, $context, $exception->getMessage(), 'warning', LogDomain::BUSINESS);
    }

    public static function serverException(Throwable $exception, array $context = []): void
    {
        self::exception($exception, $context, 'Unexpected server error.', 'error', LogDomain::ERRORS);
    }

    private static function write(string $level, string $message, array $context, LogDomain|string $domain): void
    {
        $domainName = self::domainName($domain);
        if (! self::enabled($domainName)) {
            return;
        }

        $context = self::sanitize(array_merge(self::baseContext(), ['domain' => $domainName], $context));
        $channel = config("laravel-infrastructure.logging.domain_channels.{$domainName}")
            ?? config('laravel-infrastructure.logging.channel');

        try {
            $logger = app('log');
            if (is_string($channel) && $channel !== '') {
                $logger = $logger->channel($channel);
            }
            $logger->log($level, $message, $context);
        } catch (Throwable $loggingFailure) {
            error_log($message.' | logging failure: '.$loggingFailure->getMessage());
        }
    }

    private static function baseContext(): array
    {
        $context = ['environment' => app()->environment(), 'host' => gethostname() ?: null];
        if (! app()->bound('request')) {
            return $context;
        }

        $request = request();
        if (! $request instanceof Request) {
            return $context;
        }

        $context['request'] = [
            'request_id' => $request->headers->get('X-Request-ID'),
            'method' => $request->method(),
            'url' => $request->url(),
            'route' => $request->route()?->getName(),
            'query_keys' => array_keys($request->query()),
            'ip' => $request->ip(),
            'user_type' => $request->attributes->get('user_type'),
            'user_id' => $request->attributes->get('user_id'),
        ];

        return $context;
    }

    private static function sanitize(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '********';
        }
        if ($depth >= (int) config('laravel-infrastructure.logging.max_depth', 6)) {
            return '[max-depth]';
        }
        if (is_string($value)) {
            $max = (int) config('laravel-infrastructure.logging.max_string_length', 4096);

            return strlen($value) > $max ? substr($value, 0, $max).'...[truncated]' : $value;
        }
        if (! is_array($value)) {
            return is_object($value) && ! $value instanceof Throwable
                ? (method_exists($value, '__toString') ? (string) $value : $value::class)
                : $value;
        }

        $sanitized = [];
        $limit = (int) config('laravel-infrastructure.logging.max_array_items', 100);
        foreach (array_slice($value, 0, $limit, true) as $itemKey => $itemValue) {
            $sanitized[$itemKey] = self::sanitize($itemValue, is_string($itemKey) ? $itemKey : null, $depth + 1);
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalized === $sensitiveKey || str_ends_with($normalized, '_'.$sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private static function domainName(LogDomain|string $domain): string
    {
        return $domain instanceof LogDomain ? $domain->value : $domain;
    }
}
