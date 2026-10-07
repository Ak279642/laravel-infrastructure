<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class ModelInfrastructureTest extends TestCase
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
        $app['config']->set('filesystems.default', 'public');
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/public'),
            'url' => 'http://localhost/storage',
            'visibility' => 'public',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('infrastructure_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Storage::fake('public');
    }

    public function test_base_model_generates_unique_slugs_and_cleans_replaced_files(): void
    {
        Storage::disk('public')->put('documents/old.txt', 'old');
        Storage::disk('public')->put('documents/new.txt', 'new');

        $first = InfrastructureDocument::query()->create([
            'name' => 'Quarterly Report',
            'file_path' => 'documents/old.txt',
        ]);

        self::assertSame('quarterly-report', $first->slug);

        $second = InfrastructureDocument::query()->create([
            'name' => 'Quarterly Report',
        ]);

        self::assertSame('quarterly-report-1', $second->slug);

        $first->update([
            'name' => 'Annual Report',
            'file_path' => 'documents/new.txt',
        ]);

        self::assertSame('annual-report', $first->fresh()->slug);
        Storage::disk('public')->assertMissing('documents/old.txt');
        Storage::disk('public')->assertExists('documents/new.txt');

        $first->delete();

        Storage::disk('public')->assertMissing('documents/new.txt');
    }

    public function test_file_storage_stores_uploaded_files_with_safe_generated_names(): void
    {
        $path = $this->app
            ->make(FileStorage::class)
            ->store(
                UploadedFile::fake()->create(
                    'contract.pdf',
                    10,
                    'application/pdf',
                ),
                directory: 'contracts',
            );

        self::assertStringStartsWith('contracts/', $path);
        self::assertStringEndsWith('.pdf', $path);
        Storage::disk('public')->assertExists($path);
    }
}

final class InfrastructureDocument extends BaseModel
{
    protected $table = 'infrastructure_documents';

    protected $fillable = [
        'name',
        'file_path',
    ];

    protected function slugOptions(): array
    {
        return [
            'enabled' => true,
            'source' => 'name',
            'regenerate_on_update' => true,
        ];
    }

    protected function fileAttributes(): array
    {
        return [
            'file_path' => [
                'disk' => 'public',
                'delete_on_replace' => true,
                'delete_on_delete' => true,
            ],
        ];
    }
}
