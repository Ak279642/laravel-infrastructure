<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Exceptions\InvalidFilePathException;
use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class FileLifecycleSecurityTest extends TestCase
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

        Schema::create('file_security_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Storage::fake('public');
    }

    public function test_directory_traversal_is_rejected(): void
    {
        $this->expectException(
            InvalidFilePathException::class,
        );

        $this->app
            ->make(FileStorage::class)
            ->store(
                UploadedFile::fake()->create(
                    'document.pdf',
                    10,
                    'application/pdf',
                ),
                '../outside',
            );
    }

    public function test_custom_filename_cannot_include_a_path(): void
    {
        $this->expectException(
            InvalidFilePathException::class,
        );

        $this->app
            ->make(FileStorage::class)
            ->store(
                UploadedFile::fake()->create(
                    'document.pdf',
                    10,
                    'application/pdf',
                ),
                'documents',
                'public',
                '../outside.pdf',
            );
    }

    public function test_delete_rejects_traversal_paths(): void
    {
        $this->expectException(
            InvalidFilePathException::class,
        );

        $this->app
            ->make(FileStorage::class)
            ->delete(
                '../outside.txt',
                'public',
            );
    }

    public function test_replaced_file_is_not_deleted_until_transaction_commits(): void
    {
        Storage::disk('public')->put(
            'documents/old.txt',
            'old',
        );
        Storage::disk('public')->put(
            'documents/new.txt',
            'new',
        );

        $document = FileSecurityDocument::query()->create([
            'name' => 'Document',
            'file_path' => 'documents/old.txt',
        ]);

        DB::beginTransaction();

        $document->update([
            'file_path' => 'documents/new.txt',
        ]);

        Storage::disk('public')->assertExists(
            'documents/old.txt',
        );

        DB::rollBack();

        Storage::disk('public')->assertExists(
            'documents/old.txt',
        );

        $document->refresh();

        DB::beginTransaction();

        $document->update([
            'file_path' => 'documents/new.txt',
        ]);

        Storage::disk('public')->assertExists(
            'documents/old.txt',
        );

        DB::commit();

        Storage::disk('public')->assertMissing(
            'documents/old.txt',
        );
        Storage::disk('public')->assertExists(
            'documents/new.txt',
        );
    }
}

final class FileSecurityDocument extends BaseModel
{
    protected $table = 'file_security_documents';

    protected $guarded = [];

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
