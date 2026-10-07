<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SlugBehaviorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('slug_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();

            $table->unique([
                'organization_id',
                'slug',
            ]);
        });
    }

    public function test_normal_duplicate_scoped_and_manual_slugs(): void
    {
        $first = SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => 'Hello World',
        ]);

        $duplicate = SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => 'Hello World',
        ]);

        $otherScope = SlugRecord::query()->create([
            'organization_id' => 2,
            'name' => 'Hello World',
        ]);

        $manual = SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => 'Ignored Source',
            'slug' => 'manual-slug',
        ]);

        self::assertSame('hello-world', $first->slug);
        self::assertSame('hello-world-1', $duplicate->slug);
        self::assertSame('hello-world', $otherScope->slug);
        self::assertSame('manual-slug', $manual->slug);
    }

    public function test_slug_regeneration_empty_source_and_special_characters(): void
    {
        $record = SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => 'Crème Brûlée!',
        ]);

        self::assertSame(
            'creme-brulee',
            $record->slug,
        );

        $record->update([
            'name' => 'Updated Name',
        ]);

        self::assertSame(
            'updated-name',
            $record->slug,
        );

        $empty = SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => null,
        ]);

        self::assertNull($empty->slug);
    }

    public function test_database_unique_constraint_is_final_race_protection(): void
    {
        SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => 'First',
            'slug' => 'forced',
        ]);

        $this->expectException(QueryException::class);

        SlugRecord::query()->create([
            'organization_id' => 1,
            'name' => 'Second',
            'slug' => 'forced',
        ]);
    }
}

final class SlugRecord extends BaseModel
{
    protected $table = 'slug_records';

    protected $guarded = [];

    protected function slugOptions(): array
    {
        return [
            'enabled' => true,
            'source' => 'name',
            'scope' => ['organization_id'],
            'regenerate_on_update' => true,
        ];
    }
}
