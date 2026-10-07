<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ValidationContextTest extends TestCase
{
    public function test_aliases_and_model_index_are_available(): void
    {
        $model = new ValidationContextModel;
        $model->setRawAttributes(['id' => 42, 'name' => 'Resolved'], true);

        $context = new ValidationContext;
        $context->put('customer', $model);

        self::assertSame($model, $context->getModel('customer'));
        self::assertSame($model, $context->getModel(ValidationContextModel::class));
        self::assertSame($model, $context->findModel(ValidationContextModel::class, 42));
        self::assertSame($model, $context->requireModel('customer', ValidationContextModel::class));
    }

    public function test_collections_are_indexed_by_model_class_and_id(): void
    {
        $first = new ValidationContextModel;
        $first->setRawAttributes(['id' => 1], true);

        $second = new ValidationContextModel;
        $second->setRawAttributes(['id' => 2], true);

        $context = new ValidationContext;
        $context->put('customers', new Collection([$first, $second]));

        self::assertSame($second, $context->findModel(ValidationContextModel::class, 2));
        self::assertCount(2, $context->requireCollection('customers', ValidationContextModel::class));
    }

    public function test_missing_required_alias_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ValidationContext)->requireModel('customer');
    }
}

final class ValidationContextModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
