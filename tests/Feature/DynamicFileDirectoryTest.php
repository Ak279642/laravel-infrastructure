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
