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
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;

final class ImageProcessingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filesystems.default', 'public');
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path(
                'framework/testing/disks/image-processing',
            ),
            'visibility' => 'public',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            self::markTestSkipped(
                'Image processing tests require ext-gd.',
            );
        }

        Storage::fake('public');

        Schema::create(
            'image_processing_documents',
            function (Blueprint $table): void {
                $table->id();
                $table->string('image_path')->nullable();
                $table->string('thumbnail_path')->nullable();
            },
        );
    }

    public function test_model_upload_can_convert_to_webp_and_scale_down(): void
    {
        $model = ImageProcessingDocument::query()->create([
            'image_path' => UploadedFile::fake()->image(
                'photo.jpg',
                1200,
                800,
            ),
        ]);

        self::assertIsString($model->image_path);
        self::assertStringEndsWith(
            '.webp',
            $model->image_path,
        );

        Storage::disk('public')->assertExists(
            $model->image_path,
        );

        $image = $this->decodeStoredImage(
            Storage::disk('public')->get(
                $model->image_path,
            ),
        );

        self::assertSame(720, $image->width());
        self::assertSame(480, $image->height());
    }

    public function test_cover_down_creates_square_thumbnail(): void
    {
        $model = ImageProcessingDocument::query()->create([
            'thumbnail_path' => UploadedFile::fake()->image(
                'photo.jpg',
                1200,
                800,
            ),
        ]);

        self::assertIsString($model->thumbnail_path);
        self::assertStringEndsWith(
            '.webp',
            $model->thumbnail_path,
        );

        $image = $this->decodeStoredImage(
            Storage::disk('public')->get(
                $model->thumbnail_path,
            ),
        );

        self::assertSame(300, $image->width());
        self::assertSame(300, $image->height());
    }

    private function decodeStoredImage(
        string $contents,
    ): object {
        $manager = new ImageManager(
            GdDriver::class,
        );

        return method_exists(
            $manager,
            'decodeBinary',
        )
            ? $manager->decodeBinary($contents)
            : $manager->read($contents);
    }

    public function test_processed_image_is_removed_when_transaction_rolls_back(): void
    {
        $path = null;

        DB::beginTransaction();

        try {
            $model = ImageProcessingDocument::query()->create([
                'image_path' => UploadedFile::fake()->image(
                    'rollback.jpg',
                    1200,
                    800,
                ),
            ]);

            $path = $model->image_path;

            self::assertIsString($path);
            Storage::disk('public')->assertExists($path);
        } finally {
            DB::rollBack();
        }

        self::assertIsString($path);
        Storage::disk('public')->assertMissing($path);
    }
}

final class ImageProcessingDocument extends BaseModel
{
    public $timestamps = false;

    protected $table = 'image_processing_documents';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'image_path' => [
                'disk' => 'public',
                'directory' => 'images/processed',

                'image' => [
                    'format' => 'webp',
                    'resize' => 'scale_down',
                    'width' => 720,
                    'height' => 720,
                    'quality' => 80,
                ],
            ],

            'thumbnail_path' => [
                'disk' => 'public',
                'directory' => 'images/thumbnails',

                'image' => [
                    'format' => 'webp',
                    'resize' => 'cover_down',
                    'width' => 300,
                    'height' => 300,
                    'quality' => 80,
                    'position' => 'center',
                ],
            ],
        ];
    }
}
