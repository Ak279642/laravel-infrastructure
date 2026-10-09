<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AssetModelAuthorizationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.assets.resources', [
            'acl-document' => AssetHookDocument::class,
        ]);
        $app['config']->set('laravel-infrastructure.assets.folder_access.public', [
            '*' => ['signed' => false, 'guard' => null],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Schema::create('asset_hook_documents', static function (Blueprint $table): void {
            $table->id();
            $table->string('file_path')->nullable();
            $table->boolean('allowed')->default(false);
        });
    }

    public function test_model_denial_produces_403_image_despite_existing_file(): void
    {
        Storage::disk('public')->put('private/diagram.webp', file_get_contents(
            dirname(__DIR__, 2).'/resources/images/file-not-found.webp'));
        $record = AssetHookDocument::query()->create([
            'file_path' => 'private/diagram.webp', 'allowed' => false,
        ]);

        $this->get($record->fileAssetUrl('file_path'))
            ->assertForbidden()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_model_authorization_allows_file_after_check(): void
    {
        Storage::disk('public')->put('private/diagram.webp', file_get_contents(
            dirname(__DIR__, 2).'/resources/images/file-not-found.webp'));
        $record = AssetHookDocument::query()->create([
            'file_path' => 'private/diagram.webp', 'allowed' => true,
        ]);

        $this->get($record->fileAssetUrl('file_path'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0');
    }
}

final class AssetHookDocument extends BaseModel
{
    public $timestamps = false;

    protected $table = 'asset_hook_documents';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'file_path' => [
                'disk' => 'public', 'directory' => 'private',
                'access' => ['signed' => false, 'guard' => null],
            ],
        ];
    }

    public function authorizesAssetField(string $field): bool
    {
        return $field === 'file_path' && (bool) $this->getAttribute('allowed');
    }
}
