<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use RuntimeException;

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
            $table->string('slug')->nullable()->unique();
            $table->string('seo_slug')->nullable()->unique();
            $table->string('avatar_path')->nullable();
            $table->string('document_path')->nullable();
            $table->string('legacy_path')->nullable();
            $table->string('failure_key')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('model_slug_file_plain_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path')->nullable();
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

    public function test_slug_generation_handles_unicode_and_empty_slug_sources(): void
    {
        $unicode = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Über Café',
            'seo_title' => 'Résumé Search',
        ]);

        self::assertSame('uber-cafe', $unicode->slug);
        self::assertSame('resume-search', $unicode->seo_slug);

        $empty = ModelSlugFileAuditDocument::query()->create([
            'title' => '---',
            'seo_title' => '***',
        ]);

        self::assertNull($empty->slug);
        self::assertNull($empty->seo_slug);
    }

    public function test_soft_deleted_records_continue_reserving_unique_slugs(): void
    {
        $deleted = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Reserved Slug',
            'seo_title' => 'Reserved SEO',
        ]);

        $deleted->delete();

        $replacement = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Reserved Slug',
            'seo_title' => 'Reserved SEO',
        ]);

        self::assertSame('reserved-slug-1', $replacement->slug);
        self::assertSame('reserved-seo-1', $replacement->seo_slug);
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

    public function test_file_replacement_cleanup_waits_for_transaction_commit(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Transactional File',
            'seo_title' => 'Transactional File SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'original.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        $original = $model->avatar_path;
        $replacement = null;

        DB::beginTransaction();

        try {
            $model->avatar_path = UploadedFile::fake()->create(
                'replacement.jpg',
                5,
                'image/jpeg',
            );
            $model->save();
            $replacement = $model->avatar_path;

            Storage::disk('public')->assertExists($original);
            Storage::disk('public')->assertExists($replacement);

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        self::assertIsString($replacement);
        Storage::disk('public')->assertMissing($original);
        Storage::disk('public')->assertExists($replacement);
    }

    public function test_file_replacement_rollback_preserves_database_referenced_file(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Rollback File',
            'seo_title' => 'Rollback File SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'original.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        $original = $model->avatar_path;

        DB::beginTransaction();

        try {
            $model->avatar_path = UploadedFile::fake()->create(
                'replacement.jpg',
                5,
                'image/jpeg',
            );
            $model->save();

            Storage::disk('public')->assertExists($original);
        } finally {
            DB::rollBack();
        }

        $model->refresh();

        self::assertSame($original, $model->avatar_path);
        Storage::disk('public')->assertExists($original);
    }

    public function test_force_delete_rollback_does_not_delete_model_file(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Rollback Delete',
            'seo_title' => 'Rollback Delete SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'original.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        $path = $model->avatar_path;

        DB::beginTransaction();

        try {
            $model->forceDelete();

            Storage::disk('public')->assertExists($path);
        } finally {
            DB::rollBack();
        }

        Storage::disk('public')->assertExists($path);
        self::assertNotNull(
            ModelSlugFileAuditDocument::query()->find($model->getKey()),
        );
    }

    public function test_failed_create_removes_newly_uploaded_file(): void
    {
        ModelSlugFileAuditDocument::query()->create([
            'title' => 'Existing',
            'seo_title' => 'Existing SEO',
            'failure_key' => 'duplicate',
        ]);

        try {
            ModelSlugFileAuditDocument::query()->create([
                'title' => 'Failing Create',
                'seo_title' => 'Failing Create SEO',
                'failure_key' => 'duplicate',
                'avatar_path' => UploadedFile::fake()->create(
                    'orphan.jpg',
                    5,
                    'image/jpeg',
                ),
            ]);

            self::fail('Expected duplicate-key create failure.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(
            [],
            Storage::disk('public')->allFiles(
                'models/documents/avatars',
            ),
        );
    }

    public function test_failed_update_removes_new_file_and_preserves_old_file(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Update Target',
            'seo_title' => 'Update Target SEO',
            'failure_key' => 'first',
            'avatar_path' => UploadedFile::fake()->create(
                'original.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        ModelSlugFileAuditDocument::query()->create([
            'title' => 'Conflict',
            'seo_title' => 'Conflict SEO',
            'failure_key' => 'second',
        ]);

        $original = $model->avatar_path;

        try {
            $model->failure_key = 'second';
            $model->avatar_path = UploadedFile::fake()->create(
                'replacement.jpg',
                5,
                'image/jpeg',
            );
            $model->save();

            self::fail('Expected duplicate-key update failure.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        Storage::disk('public')->assertExists($original);
        self::assertSame(
            [$original],
            Storage::disk('public')->allFiles(
                'models/documents/avatars',
            ),
        );
    }

    public function test_transaction_rollback_removes_new_file_and_keeps_old_file(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Rollback Replacement',
            'seo_title' => 'Rollback Replacement SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'original.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        $original = $model->avatar_path;
        $replacement = null;

        DB::beginTransaction();

        try {
            $model->avatar_path = UploadedFile::fake()->create(
                'replacement.jpg',
                5,
                'image/jpeg',
            );
            $model->save();
            $replacement = $model->avatar_path;

            Storage::disk('public')->assertExists($replacement);
        } finally {
            DB::rollBack();
        }

        self::assertIsString($replacement);
        Storage::disk('public')->assertExists($original);
        Storage::disk('public')->assertMissing($replacement);

        $model->refresh();

        self::assertSame($original, $model->avatar_path);
    }

    public function test_successful_non_soft_delete_removes_file(): void
    {
        $model = ModelSlugFilePlainDocument::query()->create([
            'file_path' => UploadedFile::fake()->create(
                'plain.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        $path = $model->file_path;

        Storage::disk('public')->assertExists($path);
        self::assertTrue((bool) $model->delete());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_successful_soft_delete_preserves_file_and_force_delete_removes_it(): void
    {
        $model = ModelSlugFileAuditDocument::query()->create([
            'title' => 'Delete Lifecycle',
            'seo_title' => 'Delete Lifecycle SEO',
            'avatar_path' => UploadedFile::fake()->create(
                'lifecycle.jpg',
                5,
                'image/jpeg',
            ),
        ]);

        $path = $model->avatar_path;

        $model->delete();
        Storage::disk('public')->assertExists($path);

        $model->forceDelete();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_custom_filenames_reject_paths_and_hidden_dotfiles(): void
    {
        $storage = $this->app->make(FileStorage::class);

        foreach ([
            '../escape.jpg',
            'nested/escape.jpg',
            '.htaccess',
            'avatar.bad-ext',
        ] as $filename) {
            try {
                $storage->store(
                    UploadedFile::fake()->create(
                        'avatar.jpg',
                        5,
                        'image/jpeg',
                    ),
                    directory: 'models/documents/avatars',
                    disk: 'public',
                    filename: $filename,
                );

                self::fail(
                    "Expected unsafe filename [{$filename}] to be rejected.",
                );
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_storage_audit_rejects_traversal_directory_configuration(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.storage_audit.models',
            [UnsafeStorageAuditDocument::class],
        );

        $this->artisan('infrastructure:storage-audit')
            ->assertFailed();
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
    use SoftDeletes;

    protected $table = 'model_slug_file_audit_documents';

    protected $fillable = [
        'title',
        'seo_title',
        'avatar_path',
        'document_path',
        'legacy_path',
        'failure_key',
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


final class UnsafeStorageAuditDocument extends BaseModel
{
    protected $table = 'unsafe_storage_audit_documents';

    protected function fileAttributes(): array
    {
        return [
            'path' => [
                'disk' => 'public',
                'directory' => '../outside',
                'audit' => true,
            ],
        ];
    }
}


final class ModelSlugFilePlainDocument extends BaseModel
{
    public $timestamps = false;

    protected $table = 'model_slug_file_plain_documents';

    protected $fillable = ['file_path'];

    protected function fileAttributes(): array
    {
        return [
            'file_path' => [
                'disk' => 'public',
                'directory' => 'models/plain-documents',
                'auto_upload' => true,
            ],
        ];
    }
}
