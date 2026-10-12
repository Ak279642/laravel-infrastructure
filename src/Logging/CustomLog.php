<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use BackedEnum;
use Throwable;

final class CustomLog
{
    public static function debug(
        string $message,
        array $context = [],
        BackedEnum|string $domain = LogDomain::APPLICATION,
    ): void {
        self::write('debug', $message, $context, $domain);
    }

    public static function info(
        string $message,
        array $context = [],
        BackedEnum|string $domain = LogDomain::APPLICATION,
    ): void {
        self::write('info', $message, $context, $domain);
    }

    public static function warning(
        string $message,
        array $context = [],
        BackedEnum|string $domain = LogDomain::APPLICATION,
    ): void {
        self::write('warning', $message, $context, $domain);
    }

    public static function error(
        string $message,
        array $context = [],
        BackedEnum|string $domain = LogDomain::ERRORS,
    ): void {
        self::write('error', $message, $context, $domain);
    }

    public static function exception(
        Throwable $exception,
        array $context = [],
        ?string $message = null,
        string $level = 'error',
        BackedEnum|string $domain = LogDomain::ERRORS,
    ): void {
        $context['exception'] = self::exceptionContext($exception);

        self::write(
            $level,
            $message ?? $exception::class.': '.$exception->getMessage(),
            $context,
            $domain,
        );
    }

    public static function enabled(
        BackedEnum|string $domain = LogDomain::APPLICATION,
    ): bool {
        if (! (bool) config(
            'laravel-infrastructure.logging.enabled',
            true,
        )) {
            return false;
        }

        $domains = config('laravel-infrastructure.logging.domain_enabled', []);

        // An empty array retains the existing default: all domains enabled.
        // A plain list enables only the listed domains (strings or backed enums).
        if (! is_array($domains) || $domains === []) {
            return true;
        }

        $name = self::domainName($domain);

        if (array_is_list($domains)) {
            foreach ($domains as $allowed) {
                if (($allowed instanceof BackedEnum ? (string) $allowed->value : $allowed) === $name) {
                    return true;
                }
            }

            return false;
        }

        // Backwards compatible with existing ['api' => true, 'jobs' => false]
        // configuration and defaults unspecified domains to enabled.
        return (bool) ($domains[$name] ?? true);
    }

    public static function exceptionContext(
        Throwable $exception,
    ): array {
        $context = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ((bool) config(
            'laravel-infrastructure.logging.exception_trace_enabled',
            false,
        )) {
            $context['trace'] = $exception->getTraceAsString();
        }

        return (array) LogContextRedactor::redact($context);
    }

    public static function businessException(
        Throwable $exception,
        array $context = [],
    ): void {
        self::exception(
            $exception,
            $context,
            $exception->getMessage(),
            'warning',
            LogDomain::BUSINESS,
        );
    }

    public static function serverException(
        Throwable $exception,
        array $context = [],
    ): void {
        self::exception(
            $exception,
            $context,
            'Unexpected server error.',
            'error',
            LogDomain::ERRORS,
        );
    }

    private static function write(
        string $level,
        string $message,
        array $context,
        BackedEnum|string $domain,
    ): void {
        $domainName = self::domainName($domain);

        if (! self::enabled($domainName)) {
            return;
        }

        $context = (array) LogContextRedactor::redact(
            array_merge(
                self::baseContext(),
                ['domain' => $domainName],
                $context,
            ),
        );

        $message = (string) LogContextRedactor::redact($message);

        // Explicit host mappings and channels take precedence.
        // Unmapped domains use package-managed size-rotating files.
        $mappedChannel = config('laravel-infrastructure.logging.domain_channels.'.$domainName);
        $namedChannel = 'domain_'.$domainName;
        $channel = is_string($mappedChannel) && $mappedChannel !== ''
            ? $mappedChannel
            : (config('logging.channels.'.$namedChannel) !== null
                ? $namedChannel
                : config('laravel-infrastructure.logging.channel'));

        try {
            $logger = app('log');

            if (is_string($channel) && $channel !== '') {
                $logger = $logger->channel($channel);
            } elseif (method_exists($logger, 'build')) {
                // Normalize arbitrary application-defined domain names so
                // none can traverse directories or escape the log root.
                $folder = trim((string) preg_replace(
                    '/[^a-z0-9_-]+/',
                    '-',
                    strtolower($domainName),
                ), '-');

                if ($folder === '') {
                    $folder = 'application';
                }

                $root = rtrim(
                    (string) config('laravel-infrastructure.logging.directory', storage_path('logs')),
                    '/\\',
                );

                $logger = $logger->build([
                    'driver' => 'custom',
                    'via' => DomainLoggerFactory::class,
                    'name' => $domainName,
                    'path' => $root.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.$folder.'.log',
                    'level' => config('laravel-infrastructure.logging.level', 'debug'),
                    'max_file_mb' => config('laravel-infrastructure.logging.max_file_mb', 10),
                    'days' => config('laravel-infrastructure.logging.retention_days', 14),
                ]);
            }

            $logger->log($level, $message, $context);
        } catch (Throwable $loggingFailure) {
            error_log(
                $message.' | logging failure: '.
                LogContextRedactor::redact(
                    $loggingFailure->getMessage(),
                ),
            );
        }
    }

    private static function baseContext(): array
    {
        $context = [
            'environment' => app()->environment(),
            'host' => gethostname() ?: null,
        ];

        if (! app()->bound('request')) {
            return $context;
        }

        $request = request();

        $context['request'] = [
            'request_id' => CorrelationId::resolve($request),
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

    private static function domainName(
        BackedEnum|string $domain,
    ): string {
        return $domain instanceof BackedEnum
            ? (string) $domain->value
            : $domain;
    }
}
