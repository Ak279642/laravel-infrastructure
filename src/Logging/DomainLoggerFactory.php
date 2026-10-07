<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

final class DomainLoggerFactory
{
    public function __invoke(array $config): Logger
    {
        $name = (string) ($config['name'] ?? 'application');
        $logger = new Logger($name);

        if (! (bool) ($config['enabled'] ?? true)) {
            $logger->pushHandler(new NullHandler());

            return $logger;
        }

        $handler = new DailySizeRotatingFileHandler(
            path: (string) $config['path'],
            maxBytes: max(
                1,
                (int) config('laravel-infrastructure.logging.max_file_mb', 100),
            ) * 1024 * 1024,
            retentionDays: max(
                1,
                (int) ($config['days'] ?? config(
                    'laravel-infrastructure.logging.retention_days',
                    14,
                )),
            ),
            level: (string) ($config['level'] ?? 'info'),
        );
        $handler->setFormatter(new LineFormatter(
            format: "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            dateFormat: 'Y-m-d H:i:s',
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
        ));
        $logger->pushHandler($handler);

        return $logger;
    }
}
