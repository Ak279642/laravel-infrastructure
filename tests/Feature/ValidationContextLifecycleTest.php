<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Model;

final class ValidationContextLifecycleTest extends TestCase
{
    public function test_scoped_context_does_not_survive_a_lifecycle_reset(): void
    {
        $firstContext = $this->app->make(
            ValidationContext::class,
        );

        $model = new ValidationLifecycleModel();
        $model->setRawAttributes(
            ['id' => 10],
            true,
        );

        $firstContext->put(
            'customer',
            $model,
        );

        self::assertTrue(
            $firstContext->has('customer'),
        );

        $this->app->forgetScopedInstances();

        $nextContext = $this->app->make(
            ValidationContext::class,
        );

        self::assertNotSame(
            $firstContext,
            $nextContext,
        );
        self::assertFalse(
            $nextContext->has('customer'),
        );
        self::assertNull(
            $nextContext->findModel(
                ValidationLifecycleModel::class,
                10,
            ),
        );
    }
}

final class ValidationLifecycleModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
