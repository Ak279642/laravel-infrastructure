<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Files\FileDirectoryTemplate;
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DynamicFileDirectoryTest extends TestCase
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
        $app['config']->set('filesystems.disks.private', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/private'),
        ]);
        $app['config']->set('laravel-infrastructure.storage_audit.models', [
            DynamicDirectoryInvoice::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dynamic_directory_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('invoice_path')->nullable();
            $table->string('contract_path')->nullable();
            $table->timestamps();
        });

        Storage::fake('private');
    }

    public function test_creating_with_generated_id_saves_a_private_file_under_nested_directory(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create([
            'partner_id' => 47,
            'invoice_path' => UploadedFile::fake()->create('input.pdf', 5, 'application/pdf'),
        ]);

        $expected = 'invoices/'.now()->format('Y').'/'.$invoice->getKey().'/documents/invoice.pdf';

        self::assertSame($expected, $invoice->invoice_path);
        self::assertSame($expected, $invoice->fresh()->invoice_path);
        Storage::disk('private')->assertExists($expected);
    }

    public function test_updating_existing_model_resolves_nested_directory_without_second_insert(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create(['partner_id' => 12]);

        $invoice->invoice_path = UploadedFile::fake()->create('new.pdf', 5, 'application/pdf');
        self::assertTrue($invoice->save());

        $expected = 'invoices/'.now()->format('Y').'/'.$invoice->getKey().'/documents/invoice.pdf';

        self::assertSame($expected, $invoice->fresh()->invoice_path);
        Storage::disk('private')->assertExists($expected);
        self::assertSame(1, DynamicDirectoryInvoice::query()->count());
    }

    public function test_dynamic_directory_collision_uses_next_filename_and_deletes_replaced_file(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create([
            'partner_id' => 12,
            'invoice_path' => UploadedFile::fake()->create('first.pdf', 1, 'application/pdf'),
        ]);

        $first = $invoice->invoice_path;
        $invoice->invoice_path = UploadedFile::fake()->create('second.pdf', 1, 'application/pdf');
        $invoice->save();

        self::assertStringEndsWith('/invoice-2.pdf', $invoice->invoice_path);
        Storage::disk('private')->assertMissing($first);
        Storage::disk('private')->assertExists($invoice->invoice_path);
    }

    public function test_recursive_audit_preserves_referenced_file_and_removes_only_template_shaped_orphans(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create([
            'partner_id' => 12,
            'invoice_path' => UploadedFile::fake()->create('used.pdf', 1, 'application/pdf'),
        ]);

        $base = 'invoices/'.now()->format('Y');
        $orphan = $base.'/99999/documents/orphan.pdf';
        $other = $base.'/99999/unmanaged/keep.pdf';
        $invalidYear = 'invoices/not-a-year/99999/documents/keep.pdf';
        Storage::disk('private')->put($orphan, 'unused');
        Storage::disk('private')->put($other, 'not in managed folder');
        Storage::disk('private')->put($invalidYear, 'invalid year');

        $this->artisan('infrastructure:storage-audit')->assertSuccessful();

        Storage::disk('private')->assertExists($orphan);

        $this->artisan('infrastructure:storage-audit', ['--delete' => true])
            ->assertSuccessful();

        Storage::disk('private')->assertExists($invoice->invoice_path);
        Storage::disk('private')->assertMissing($orphan);
        Storage::disk('private')->assertExists($other);
        Storage::disk('private')->assertExists($invalidYear);
    }

    public function test_directory_placeholder_rejects_unsafe_identifier(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create(['partner_id' => 12]);
        $invoice->setRawAttributes(array_merge($invoice->getAttributes(), ['id' => '../outside']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('safe single path segment');

        FileDirectoryTemplate::resolve('invoices/{id}/files', $invoice);
    }

    public function test_directory_resolver_never_lazy_loads_relationships(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create(['partner_id' => 9]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be preloaded');

        FileDirectoryTemplate::resolve('partners/{partner.id}/documents', $invoice);
    }

    public function test_directory_resolver_uses_preloaded_relationship_attributes(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create(['partner_id' => 9]);
        $partner = new DynamicDirectoryInvoice;
        $partner->setRawAttributes(['id' => 77]);
        $invoice->setRelation('partner', $partner);

        self::assertSame(
            'partners/77/documents',
            FileDirectoryTemplate::resolve('partners/{partner.id}/documents', $invoice),
        );
    }

    public function test_created_year_is_deferred_on_insert_and_stays_stable_when_file_is_replaced_next_year(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-12-31 23:50:00'));

        try {
            $invoice = DynamicCreatedYearInvoice::query()->create([
                'partner_id' => 47,
                'invoice_path' => UploadedFile::fake()->create('first.pdf', 5, 'application/pdf'),
            ]);

            $first = $invoice->invoice_path;
            self::assertSame('invoices/2025/'.$invoice->id.'/documents/invoice.pdf', $first);
            Storage::disk('private')->assertExists($first);
            self::assertSame(2025, $invoice->fresh()->created_at->year);

            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-01-31 10:00:00'));
            $invoice->invoice_path = UploadedFile::fake()->create('second.pdf', 5, 'application/pdf');
            self::assertTrue($invoice->save());

            self::assertSame('invoices/2025/'.$invoice->id.'/documents/invoice-2.pdf', $invoice->invoice_path);
            self::assertSame($invoice->invoice_path, $invoice->fresh()->invoice_path);
            Storage::disk('private')->assertMissing($first);
            Storage::disk('private')->assertExists($invoice->invoice_path);
            self::assertSame(1, DynamicCreatedYearInvoice::query()->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_created_year_alone_defers_upload_until_timestamp_is_written(): void
    {
        $invoice = DynamicCreatedYearInvoice::query()->create([
            'partner_id' => 12,
            'contract_path' => UploadedFile::fake()->create('contract.pdf', 5, 'application/pdf'),
        ]);

        $expected = 'contracts/'.$invoice->created_at->format('Y').'/contract.pdf';

        self::assertSame($expected, $invoice->contract_path);
        Storage::disk('private')->assertExists($expected);
        self::assertSame(1, DynamicCreatedYearInvoice::query()->count());
    }

    public function test_created_year_uses_historical_creation_timestamp_even_for_later_uploads(): void
    {
        $invoice = DynamicCreatedYearInvoice::query()->create([
            'partner_id' => 15,
            'created_at' => '2023-12-31 19:30:00',
        ]);

        $invoice->invoice_path = UploadedFile::fake()->create('new.pdf', 5, 'application/pdf');
        self::assertTrue($invoice->save());

        self::assertSame(
            'invoices/2023/'.$invoice->id.'/documents/invoice.pdf',
            $invoice->invoice_path,
        );
        Storage::disk('private')->assertExists($invoice->invoice_path);
    }

    public function test_created_year_audit_includes_historical_years_and_ignores_invalid_years(): void
    {
        config()->set('laravel-infrastructure.storage_audit.models', [DynamicCreatedYearInvoice::class]);

        $invoice = DynamicCreatedYearInvoice::query()->create([
            'partner_id' => 33,
            'created_at' => '2024-02-01 09:00:00',
            'invoice_path' => UploadedFile::fake()->create('original.pdf', 5, 'application/pdf'),
        ]);

        $orphan = 'invoices/2023/99999/documents/orphan.pdf';
        $other = 'invoices/2023/99999/unmanaged/keep.pdf';
        $invalidYear = 'invoices/not-a-year/99999/documents/keep.pdf';

        Storage::disk('private')->put($orphan, 'unused');
        Storage::disk('private')->put($other, 'outside managed template');
        Storage::disk('private')->put($invalidYear, 'invalid year');

        $this->artisan('infrastructure:storage-audit', ['--delete' => true])
            ->assertSuccessful();

        Storage::disk('private')->assertExists($invoice->invoice_path);
        Storage::disk('private')->assertMissing($orphan);
        Storage::disk('private')->assertExists($other);
        Storage::disk('private')->assertExists($invalidYear);
    }

    public function test_created_year_requires_a_creation_timestamp_instead_of_falling_back_to_now(): void
    {
        $invoice = new DynamicCreatedYearInvoice;
        $invoice->timestamps = false;
        $invoice->invoice_path = UploadedFile::fake()->create('original.pdf', 5, 'application/pdf');

        try {
            $invoice->save();
            self::fail('Missing creation timestamp should reject the upload.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                '[created_year] requires a valid model creation timestamp',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, DynamicCreatedYearInvoice::query()->count());
    }

    public function test_created_year_matches_only_four_digit_years_during_audit(): void
    {
        self::assertTrue(FileDirectoryTemplate::usesCreatedYear('invoices/{created_year}/{id}/documents'));
        self::assertFalse(FileDirectoryTemplate::usesCreatedYear('invoices/{year}/{id}/documents'));
        self::assertTrue(FileDirectoryTemplate::matchesFile(
            'invoices/{created_year}/{id}/documents',
            'invoices/2024/10/documents/scan.pdf',
        ));
        self::assertFalse(FileDirectoryTemplate::matchesFile(
            'invoices/{created_year}/{id}/documents',
            'invoices/2024-01/10/documents/scan.pdf',
        ));
    }

    public function test_storage_audit_refuses_template_without_static_root(): void
    {
        config()->set('laravel-infrastructure.storage_audit.models', [
            DynamicRootlessInvoice::class,
        ]);

        $this->artisan('infrastructure:storage-audit')->assertFailed();
    }

    public function test_template_rejects_partial_placeholder_segments(): void
    {
        $this->expectException(RuntimeException::class);

        FileDirectoryTemplate::auditRoot('invoices/{year}-{id}/documents');
    }

    public function test_audit_must_not_delete_on_unknown_dynamic_absolute_reference(): void
    {
        $invoice = DynamicDirectoryInvoice::query()->create(['partner_id' => 12]);
        $invoice->invoice_path = '/outside/private/invoices/'.now()->format('Y').'/'.$invoice->id.'/documents/external.pdf';
        $invoice->save();

        $orphan = 'invoices/'.now()->format('Y').'/99999/documents/orphan.pdf';
        Storage::disk('private')->put($orphan, 'orphan');

        $this->artisan('infrastructure:storage-audit', ['--delete' => true])
            ->assertFailed();

        Storage::disk('private')->assertExists($orphan);
    }
}

final class DynamicDirectoryInvoice extends BaseModel
{
    protected $table = 'dynamic_directory_invoices';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'invoice_path' => [
                'disk' => 'private',
                'directory' => 'invoices/{year}/{id}/documents',
                'filename' => 'invoice.pdf',
            ],
            'contract_path' => [
                'disk' => 'private',
                'directory' => 'invoices/{year}/{id}/contracts',
                'filename' => 'contract.pdf',
            ],
        ];
    }
}

final class DynamicRootlessInvoice extends BaseModel
{
    protected $table = 'dynamic_directory_invoices';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'invoice_path' => [
                'disk' => 'private',
                'directory' => '{year}/{id}/invoices',
            ],
        ];
    }
}

final class DynamicCreatedYearInvoice extends BaseModel
{
    protected $table = 'dynamic_directory_invoices';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'invoice_path' => [
                'disk' => 'private',
                'directory' => 'invoices/{created_year}/{id}/documents',
                'filename' => 'invoice.pdf',
            ],
            'contract_path' => [
                'disk' => 'private',
                'directory' => 'contracts/{created_year}',
                'filename' => 'contract.pdf',
            ],
        ];
    }
}
