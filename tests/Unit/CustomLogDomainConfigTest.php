<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\DailySizeRotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Ak279642\LaravelInfrastructure\Tests\TestCase;

final class CustomLogDomainConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laravel-infrastructure.logging.enabled', true);
        config()->set('laravel-infrastructure.logging.domain_enabled', []);
        config()->set('laravel-infrastructure.logging.domain_channels', []);
    }

    public function test_empty_domain_array_keeps_existing_default_all_domains_enabled(): void
    {
        self::assertTrue(CustomLog::enabled(LogDomain::API));
        self::assertTrue(CustomLog::enabled('ivr'));
        self::assertTrue(CustomLog::enabled(LogDomain::JOBS));
    }

    public function test_plain_list_accepts_only_selected_domains(): void
    {
        config()->set('laravel-infrastructure.logging.domain_enabled', ['api', 'ivr']);

        self::assertTrue(CustomLog::enabled(LogDomain::API));
        self::assertTrue(CustomLog::enabled('ivr'));
        self::assertFalse(CustomLog::enabled('business'));
        self::assertFalse(CustomLog::enabled(LogDomain::JOBS));
    }

    public function test_domain_list_also_accepts_backed_enum_values(): void
    {
        config()->set('laravel-infrastructure.logging.domain_enabled', [
            LogDomain::API,
            DomainConfigTestAppEnum::IVR,
        ]);

        self::assertTrue(CustomLog::enabled('api'));
        self::assertTrue(CustomLog::enabled('ivr'));
        self::assertFalse(CustomLog::enabled('meta'));
    }

    public function test_existing_associative_boolean_map_remains_compatible(): void
    {
        config()->set('laravel-infrastructure.logging.domain_enabled', [
            'api' => false,
            'ivr' => true,
        ]);

        self::assertFalse(CustomLog::enabled(LogDomain::API));
        self::assertTrue(CustomLog::enabled('ivr'));
        self::assertTrue(CustomLog::enabled(LogDomain::JOBS));
    }

    public function test_explicit_domain_channel_writes_to_its_own_log(): void
    {
        $path = sys_get_temp_dir().'/infrastructure-meta-'.uniqid('', true).'.log';
        config()->set('logging.channels.marketing_meta', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'info',
        ]);
        config()->set('laravel-infrastructure.logging.domain_channels', [
            'meta' => 'marketing_meta',
        ]);

        try {
            CustomLog::info('domain routing test', [], 'meta');
            self::assertFileExists($path);
            self::assertStringContainsString('domain routing test', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_unmapped_domain_uses_named_laravel_channel(): void
    {
        $path = sys_get_temp_dir().'/infrastructure-api-'.uniqid('', true).'.log';
        config()->set('logging.channels.domain_api', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'info',
        ]);

        try {
            CustomLog::info('named channel test', [], LogDomain::API);
            self::assertFileExists($path);
            self::assertStringContainsString('named channel test', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_unmapped_domains_automatically_write_separate_folder_logs(): void
    {
        $root = sys_get_temp_dir().'/infra-domain-folders-'.uniqid('', true);
        config()->set('laravel-infrastructure.logging.directory', $root);

        try {
            CustomLog::info('automatic application marker', [], LogDomain::APPLICATION);
            CustomLog::error('automatic errors marker');
            CustomLog::warning('automatic business marker', [], LogDomain::BUSINESS);

            $day = date('Y-m-d');
            self::assertFileExists($root.'/application/application_'.$day.'_001.log');
            self::assertFileExists($root.'/errors/errors_'.$day.'_001.log');
            self::assertFileExists($root.'/business/business_'.$day.'_001.log');
            self::assertStringContainsString(
                'automatic errors marker',
                (string) file_get_contents($root.'/errors/errors_'.$day.'_001.log'),
            );
        } finally {
            $this->removeTestDirectory($root);
        }
    }

    public function test_size_rotation_creates_next_segment_within_same_day(): void
    {
        $root = sys_get_temp_dir().'/infra-rotation-'.uniqid('', true);
        $handler = new DailySizeRotatingFileHandler($root.'/errors/errors.log', maxBytes: 90);
        $handler->setFormatter(new LineFormatter("%message%\n"));
        $logger = new Logger('errors');
        $logger->pushHandler($handler);

        try {
            $logger->error(str_repeat('A', 60));
            $logger->error(str_repeat('B', 60));
            $logger->error(str_repeat('C', 60));
            $day = date('Y-m-d');

            foreach (['001', '002', '003'] as $segment) {
                self::assertFileExists($root.'/errors/errors_'.$day.'_'.$segment.'.log');
            }

            self::assertStringContainsString('A', (string) file_get_contents($root.'/errors/errors_'.$day.'_001.log'));
            self::assertStringContainsString('B', (string) file_get_contents($root.'/errors/errors-'.$day.'-002.log'));
            self::assertStringContainsString('C', (string) file_get_contents($root.'/errors/errors-'.$day.'-003.log'));
        } finally {
            $handler->close();
            $this->removeTestDirectory($root);
        }
    }

    public function test_rotation_continues_existing_segment_across_instances(): void
    {
        $root = sys_get_temp_dir().'/infra-rotation-resume-'.uniqid('', true);
        $path = $root.'/api/api.log';

        try {
            foreach (['first', 'second'] as $message) {
                $handler = new DailySizeRotatingFileHandler($path, maxBytes: 200);
                $handler->setFormatter(new LineFormatter("%message%\n"));
                $logger = new Logger('api');
                $logger->pushHandler($handler);
                $logger->info($message);
                $handler->close();
            }

            $current = $root.'/api/api_'.date('Y-m-d').'_001.log';
            self::assertFileExists($current);
            self::assertFileDoesNotExist($root.'/api/api_'.date('Y-m-d').'_002.log');
            self::assertStringContainsString('first', (string) file_get_contents($current));
            self::assertStringContainsString('second', (string) file_get_contents($current));
        } finally {
            $this->removeTestDirectory($root);
        }
    }

    public function test_custom_domain_names_cannot_traverse_logging_root(): void
    {
        $root = sys_get_temp_dir().'/infra-domain-safety-'.uniqid('', true);
        config()->set('laravel-infrastructure.logging.directory', $root);

        try {
            CustomLog::info('safe domain marker', [], '../../outside');
            self::assertFileExists($root.'/outside/outside_'.date('Y-m-d').'_001.log');
        } finally {
            $this->removeTestDirectory($root);
        }
    }

    private function removeTestDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($root);
    }

    public function test_global_logging_disable_overrides_enabled_domain_list(): void
    {
        config()->set('laravel-infrastructure.logging.domain_enabled', ['api']);
        config()->set('laravel-infrastructure.logging.enabled', false);

        self::assertFalse(CustomLog::enabled('api'));
    }
}

enum DomainConfigTestAppEnum: string
{
    case IVR = 'ivr';
}
