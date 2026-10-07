<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use ReflectionMethod;
use RuntimeException;

final class LoggingSecurityTest extends TestCase
{
    public function test_exception_messages_redact_common_inline_secrets(): void
    {
        $context = CustomLog::exceptionContext(
            new RuntimeException(
                'Authorization: Bearer abc123 token=secret password=hunter2',
            ),
        );

        self::assertStringNotContainsString(
            'abc123',
            $context['message'],
        );
        self::assertStringNotContainsString(
            'secret',
            $context['message'],
        );
        self::assertStringNotContainsString(
            'hunter2',
            $context['message'],
        );
    }

    public function test_nested_sensitive_structures_are_redacted_recursively(): void
    {
        $method = new ReflectionMethod(
            CustomLog::class,
            'sanitize',
        );

        $sanitized = $method->invoke(
            null,
            [
                'user' => [
                    'password' => 'secret-password',
                    'tokens' => [
                        'access' => 'secret-token',
                    ],
                    'profile' => [
                        'note' => 'api_key=inline-secret',
                    ],
                ],
            ],
        );

        self::assertSame(
            '********',
            $sanitized['user']['password'],
        );
        self::assertSame(
            '********',
            $sanitized['user']['tokens'],
        );
        self::assertStringNotContainsString(
            'inline-secret',
            $sanitized['user']['profile']['note'],
        );
    }
}
