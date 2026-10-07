<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Logging\CorrelationId;
use Ak279642\LaravelInfrastructure\Logging\LogContextRedactor;
use Illuminate\Config\Repository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;

final class LogContextRedactorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = new Repository([
            'laravel-infrastructure' => [
                'logging' => [
                    'max_depth' => 6,
                    'max_string_length' => 4096,
                    'max_array_items' => 100,
                    'correlation_header' => 'X-Request-ID',
                ],
            ],
        ]);

        app()->instance('config', $config);
    }

    public function test_sensitive_keys_and_inline_credentials_are_redacted_recursively(): void
    {
        $redacted = LogContextRedactor::redact([
            'password' => 'top-secret',
            'headers' => [
                'Authorization' => 'Bearer abc.def.ghi',
                'X-Api-Key' => 'key-123',
            ],
            'message' => 'password=hunter2 token=tok-123',
            'safe' => 'visible',
        ]);

        self::assertSame('********', $redacted['password']);
        self::assertSame(
            '********',
            $redacted['headers']['Authorization'],
        );
        self::assertSame(
            '********',
            $redacted['headers']['X-Api-Key'],
        );
        self::assertStringNotContainsString(
            'hunter2',
            $redacted['message'],
        );
        self::assertStringNotContainsString(
            'tok-123',
            $redacted['message'],
        );
        self::assertSame('visible', $redacted['safe']);
    }

    public function test_correlation_id_validation_rejects_unbounded_or_unsafe_values(): void
    {
        self::assertTrue(CorrelationId::isValid('request-123:abc'));
        self::assertFalse(CorrelationId::isValid('request id'));
        self::assertFalse(CorrelationId::isValid(str_repeat('a', 129)));
    }
}
