<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use PHPUnit\Framework\TestCase;

final class CacheTagTest extends TestCase
{
    public function test_tags_are_flattened_trimmed_and_unique(): void
    {
        self::assertSame(
            ['users', 'users:1', 'roles'],
            CacheTag::tags(' users ', ['users:1', 'roles', 'users']),
        );
    }

    public function test_model_tag_is_namespace_safe(): void
    {
        self::assertSame('model:app.models.user', CacheTag::fromModel('App\\Models\\User'));
    }
}
