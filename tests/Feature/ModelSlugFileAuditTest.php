<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class ModelSlugFileAuditTest extends TestCase
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
        $app['config']->set(
            'laravel-infrastructure.storage_audit.models',
            [ModelSlugFileAuditDocument::class],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('model_slug_file_audit_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('seo_title');
            $table->string('slug')->unique();
            $table->string('seo_slug')->unique();
            $table->string('avatar_path')->nullable();
            $table->string('document_path')->nullable();
            $table->string('legacy_path')->nullable();
            $table->timestamps();
        });

        Storage::fake('public');
    }

    public function test_model_can_generate_multiple_independent_slug_fields(): void
    {
        $first = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Quarterly Report',
            'seo_title' => 'Financial Results',
        ]);

        self::assertSame('quarterly-report', $first->slug);
        self::assertSame('financial-results', $first->seo_slug);

        $second = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Quarterly Report',
            'seo_title' => 'Financial Results',
        ]);

        self::assertSame('quarterly-report-1', $second->slug);
        self::assertSame('financial-results-1', $second->seo_slug);

        $first->update([
            'title' => 'Annual Report',
            'seo_title' => 'Updated Search Title',
        ]);

        self::assertSame('annual-report', $first->fresh()->slug);
        self::assertSame('financial-results', $first->fresh()->seo_slug);
    }

    public function test_uploaded_files_are_automatically_stored_in_model_configured_directories(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Document',
            'seo_title' => 'Document SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'avatar.jpg',
                5,
                'image/jpeg',
            ),
            'document_path' => UploadedFile::fake()->create(
                'contract.pdf',
                10,
                'application/pdf',
            ),
        ]);

        self::assertIsString($model->avatar_path);
        self::assertIsString($model->document_path);
        self::assertStringStartsWith(
            'models/documents/avatars/',
            $model->avatar_path,
        );
        self::assertStringStartsWith(
            'models/documents/files/',
            $model->document_path,
        );

        Storage::disk('public')->assertExists($model->avatar_path);
        Storage::disk('public')->assertExists($model->document_path);
    }

    public function test_storage_audit_is_dry_run_by_default_and_never_scans_outside_model_owned_directories(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Audited Document',
            'seo_title' => 'Audited Document SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'avatar.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        Storage::disk('public')->put(
            'models/documents/avatars/orphan.jpg',
            'orphan',
        );
        Storage::disk('public')->put(
            'uploads/global-orphan.txt',
            'must-not-be-scanned',
        );
        Storage::disk('public')->put(
            'unmanaged/outside.txt',
            'must-not-be-scanned',
        );

        $this->artisan('infrastructure:storage-audit')
            ->assertSuccessful();

        Storage::disk('public')->assertExists(
            'models/documents/avatars/orphan.jpg',
        );

        $this->artisan(
            'infrastructure:storage-audit',
            ['--delete' => true],
        )->assertSuccessful();

        Storage::disk('public')->assertExists($model->avatar_path);
        Storage::disk('public')->assertMissing(
            'models/documents/avatars/orphan.jpg',
        );
        Storage::disk('public')->assertExists(
            'uploads/global-orphan.txt',
        );
        Storage::disk('public')->assertExists(
            'unmanaged/outside.txt',
        );
    }

    public function test_file_field_without_model_directory_is_not_auditable(): void
    {
        $model = new ModelSlugFileAuditDocument;

        self::assertArrayHasKey(
            'avatar_path',
            $model->auditableFileAttributes(),
        );
        self::assertArrayHasKey(
            'document_path',
            $model->auditableFileAttributes(),
        );
        self::assertArrayNotHasKey(
            'legacy_path',
            $model->auditableFileAttributes(),
        );
    }
}

final class ModelSlugFileAuditDocument extends BaseModel
{
    protected $table = 'model_slug_file_audit_documents';

    protected $fillable = [
        'title',
        'seo_title',
        'avatar_path',
        'document_path',
        'legacy_path',
    ];

    protected function slugFields(): array
    {
        return [
            'slug' => [
                'source' => 'title',
                'unique' => true,
                'regenerate_on_update' => true,
            ],
            'seo_slug' => [
                'source' => 'seo_title',
                'unique' => true,
                'regenerate_on_update' => false,
            ],
        ];
    }

    protected function fileAttributes(): array
    {
        return [
            'avatar_path' => [
                'disk' => 'public',
                'directory' => 'models/documents/avatars',
                'auto_upload' => true,
                'audit' => true,
            ],
            'document_path' => [
                'disk' => 'public',
                'directory' => 'models/documents/files',
                'auto_upload' => true,
                'audit' => true,
            ],
            // Auto-upload may still use the global directory for compatibility,
            // but the audit command will never scan it because this model does
            // not explicitly own a directory for this field.
            'legacy_path' => [
                'disk' => 'public',
                'auto_upload' => true,
                'audit' => true,
            ],
        ];
    }
}
