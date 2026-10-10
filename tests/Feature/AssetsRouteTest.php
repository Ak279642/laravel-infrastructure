<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AssetsRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.assets.disk_aliases', ['media' => 'public']);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Schema::create('media_route_brands', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });
        Schema::create('media_route_categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });
        Schema::create('media_slug_products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('media_route_products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    public function test_filename_from_uses_loaded_relations_without_hidden_queries(): void
    {
        $product = new MediaRouteProduct(['name' => 'iPhone 16 Pro']);
        $brand = new MediaRouteBrand;
        $brand->slug = 'apple';
        $brand->save();
        $category = new MediaRouteCategory;
        $category->slug = 'smartphones';
        $category->save();
        $product->brand_id = $brand->id;
        $product->setRelation('brand', $brand);
        $product->category_id = $category->id;
        $product->setRelation('category', $category);
        $product->image = UploadedFile::fake()->createWithContent('upload.webp', 'first');
        $product->save();

        self::assertSame('products/iphone-16-pro-apple-smartphones.webp', $product->image);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $url = $product->getFileUrl('image');
        self::assertEmpty(DB::getQueryLog());
        self::assertStringContainsString('/media/products/iphone-16-pro-apple-smartphones.webp?v=', $url);
        self::assertStringNotContainsString('/'.$product->getKey().'/image/', $url);
        $this->get($url)->assertOk();
        self::assertEmpty(DB::getQueryLog());
    }

    public function test_collision_adds_numeric_suffix_without_overwriting_first_file(): void
    {
        $first = $this->newProduct('first');
        $second = $this->newProduct('second');

        self::assertSame('products/iphone-16-pro-apple-smartphones.webp', $first->image);
        self::assertSame('products/iphone-16-pro-apple-smartphones-2.webp', $second->image);
        self::assertSame('first', Storage::disk('public')->get($first->image));
        self::assertSame('second', Storage::disk('public')->get($second->image));
        $this->get($second->getFileUrl('image'))->assertOk();
    }

    public function test_existing_uuid_filename_can_be_migrated_with_dry_run_and_apply(): void
    {
        $product = new MediaRouteProduct(['name' => 'iPhone 16 Pro']);
        $brand = new MediaRouteBrand;
        $brand->slug = 'apple';
        $brand->save();
        $category = new MediaRouteCategory;
        $category->slug = 'smartphones';
        $category->save();
        $product->brand_id = $brand->id;
        $product->setRelation('brand', $brand);
        $product->category_id = $category->id;
        $product->setRelation('category', $category);
        $product->image = 'products/legacy-hash.webp';
        $product->save();
        Storage::disk('public')->put($product->image, 'old');
        $this->artisan('infrastructure:media-rename', ['model' => MediaRouteProduct::class])
            ->assertSuccessful();
        self::assertSame('products/legacy-hash.webp', $product->fresh()->image);

        $this->artisan('infrastructure:media-rename', [
            'model' => MediaRouteProduct::class, '--apply' => true,
        ])->assertSuccessful();
        self::assertSame('products/iphone-16-pro-apple-smartphones.webp', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/legacy-hash.webp');
        Storage::disk('public')->assertExists('products/iphone-16-pro-apple-smartphones.webp');
    }

    public function test_optional_reference_cast_supports_file_object_url(): void
    {
        $plain = $this->newProduct('cast-test');
        $cast = MediaRouteProductWithFileCast::query()->findOrFail($plain->id);
        self::assertInstanceOf(
            \Ak279642\LaravelInfrastructure\Files\FileReference::class,
            $cast->image,
        );
        self::assertSame($cast->getFileUrl('image'), $cast->image->getFileUrl());
        self::assertSame($plain->image, (string) $cast->image);
    }

    public function test_unloaded_relationship_fails_without_lazy_query(): void
    {
        $product = new MediaRouteProduct(['name' => 'iPhone 16 Pro']);
        $product->image = UploadedFile::fake()->createWithContent('x.webp', 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be loaded');
        $product->save();
    }

    public function test_custom_url_name_cannot_point_to_nonexistent_file(): void
    {
        $product = $this->newProduct('bytes');
        $this->expectException(\InvalidArgumentException::class);
        $product->getFileUrl('image', 'unrelated-filename');
    }

    public function test_only_public_disks_can_be_aliased(): void
    {
        config()->set('laravel-infrastructure.assets.disk_aliases', ['private-files' => 'private']);
        $this->expectException(\RuntimeException::class);
        \Ak279642\LaravelInfrastructure\Files\MediaUrl::aliases();
    }

    public function test_slug_is_generated_before_file_is_named_during_create(): void
    {
        $model = new MediaSlugProduct(['name' => 'Camera Drone']);
        $model->image = UploadedFile::fake()->createWithContent('drone.webp', 'data');
        $model->save();

        self::assertSame('camera-drone', $model->slug);
        self::assertSame('products/camera-drone.webp', $model->image);
    }

    private function newProduct(string $data): MediaRouteProduct
    {
        $product = new MediaRouteProduct(['name' => 'iPhone 16 Pro']);
        $brand = new MediaRouteBrand;
        $brand->slug = 'apple';
        $brand->save();
        $category = new MediaRouteCategory;
        $category->slug = 'smartphones';
        $category->save();
        $product->brand_id = $brand->id;
        $product->setRelation('brand', $brand);
        $product->category_id = $category->id;
        $product->setRelation('category', $category);
        $product->image = UploadedFile::fake()->createWithContent('upload.webp', $data);
        $product->save();
        return $product;
    }
}

class MediaRouteProduct extends BaseModel
{
    protected $table = 'media_route_products';
    protected $guarded = [];

    public function brand()
    {
        return $this->belongsTo(MediaRouteBrand::class, 'brand_id');
    }

    public function category()
    {
        return $this->belongsTo(MediaRouteCategory::class, 'category_id');
    }

    protected function fileAttributes(): array
    {
        return [
            'image' => [
                'directory' => 'products',
                'filename_from' => ['name', 'brand.slug', 'category.slug'],
            ],
        ];
    }
}

final class MediaRouteBrand extends \Illuminate\Database\Eloquent\Model
{
    public $timestamps = false;
    protected $table = 'media_route_brands';
}

final class MediaRouteCategory extends \Illuminate\Database\Eloquent\Model
{
    public $timestamps = false;
    protected $table = 'media_route_categories';
}

final class MediaSlugProduct extends BaseModel
{
    protected $table = 'media_slug_products';
    protected $guarded = [];

    protected function slugFields(): array
    {
        return ['slug' => ['source' => 'name']];
    }

    protected function fileAttributes(): array
    {
        return ['image' => ['directory' => 'products', 'filename_from' => 'slug']];
    }
}

final class MediaRouteProductWithFileCast extends MediaRouteProduct
{
    protected $casts = [
        'image' => \Ak279642\LaravelInfrastructure\Files\FileReferenceCast::class,
    ];
}
