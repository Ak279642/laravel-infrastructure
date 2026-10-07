<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Context\OperationContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationContextTest extends TestCase
{
    public function test_context_exposes_values(): void
    {
        $context = new OperationContext(['tenant_id' => 42]);

        self::assertTrue($context->has('tenant_id'));
        self::assertSame(42, $context->get('tenant_id'));
        self::assertNull($context->getOrNull('missing'));
    }

    public function test_get_rejects_missing_required_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OperationContext([]))->get('tenant_id');
    }
}
