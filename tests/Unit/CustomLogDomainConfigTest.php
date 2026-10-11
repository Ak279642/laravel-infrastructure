<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Ak279642\LaravelInfrastructure\Tests\TestCase;

final class CustomLogDomainConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laravel-infrastructure.logging.enabled', true);
        config()->set('laravel-infrastructure.logging.domain_enabled', []);
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
