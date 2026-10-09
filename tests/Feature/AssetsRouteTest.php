<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class AssetsRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.providers.asset_users', [
            'driver' => 'eloquent',
            'model' => AssetGuardUser::class,
        ]);
        $app['config']->set('auth.guards.admin', [
            'driver' => 'session',
            'provider' => 'asset_users',
        ]);
        $app['config']->set('auth.guards.staff', [
            'driver' => 'session',
            'provider' => 'asset_users',
        ]);

        $app['config']->set(
            'laravel-infrastructure.assets.resources',
            [
                'document' => AssetRouteDocument::class,
            ],
        );

        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path(
                'framework/testing/disks/assets-public',
            ),
            'visibility' => 'public',
        ]);

        $app['config']->set('filesystems.disks.private', [
            'driver' => 'local',
            'root' => storage_path(
                'framework/testing/disks/assets-private',
            ),
            'visibility' => 'private',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');

        Schema::create(
            'asset_route_documents',
            function (Blueprint $table): void {
                $table->id();
                $table->string('file_path')->nullable();
            },
        );

        Schema::create(
            'signed_asset_route_documents',
            function (Blueprint $table): void {
                $table->id();
                $table->string('file_path')->nullable();
            },
        );

        Schema::create(
            'asset_guard_users',
            function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('password')->nullable();
                $table->rememberToken();
            },
        );
    }

    public function test_signed_asset_route_serves_allowed_public_file(): void
    {
        Storage::disk('public')->put(
            'products/documents/manual.txt',
            'manual-content',
        );

        $url = URL::temporarySignedRoute(
            'laravel-infrastructure.assets.show',
            now()->addMinutes(5),
            [
                'disk' => 'public',
                'path' => 'products/documents/manual.txt',
            ],
        );

        $this->get($url)->assertOk();
    }

    public function test_unsigned_request_is_rejected_when_signature_is_required(): void
    {
        Storage::disk('public')->put(
            'products/documents/manual.txt',
            'manual-content',
        );

        $this->get(
            route(
                'laravel-infrastructure.assets.show',
                [
                    'disk' => 'public',
                    'path' => 'products/documents/manual.txt',
                ],
            ),
        )->assertOk();
    }

    public function test_same_disk_can_have_public_and_guard_protected_folders(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.folder_access.public',
            [
                '*' => [
                    'signed' => false,
                    'guard' => null,
                ],

                'products/admin' => [
                    'guard' => 'admin',
                ],
            ],
        );

        Storage::disk('public')->put(
            'products/images/photo.txt',
            'photo',
        );
        Storage::disk('public')->put(
            'products/admin/report.txt',
            'report',
        );

        $this->get(
            route(
                'laravel-infrastructure.assets.show',
                [
                    'disk' => 'public',
                    'path' => 'products/images/photo.txt',
                ],
            ),
        )->assertOk();

        $protectedUrl = route(
            'laravel-infrastructure.assets.show',
            [
                'disk' => 'public',
                'path' => 'products/admin/report.txt',
            ],
        );

        $this->get($protectedUrl)->assertOk();

        $admin = AssetGuardUser::query()->create([
            'name' => 'Admin',
        ]);

        $this->actingAs($admin, 'admin');

        $this->get($protectedUrl)->assertOk();
    }

    public function test_model_file_access_can_require_only_a_guard(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.folder_access.public',
            [
                '*' => [
                    'signed' => true,
                    'guard' => null,
                ],
            ],
        );

        Storage::disk('public')->put(
            'products/private/manual.txt',
            'manual-content',
        );

        $document = AssetRouteDocument::query()->create([
            'file_path' => 'products/private/manual.txt',
        ]);

        $url = $document->fileAssetUrl('file_path');

        self::assertIsString($url);
        $urlPath = parse_url($url, PHP_URL_PATH);
        self::assertIsString($urlPath);
        self::assertMatchesRegularExpression(
            '#/infrastructure/assets/[^/]+/'.
            preg_quote((string) $document->getKey(), '#').
            '/file-path-'.$document->getKey().'\\.txt$#',
            $urlPath,
        );
        self::assertStringNotContainsString('/model/', $url);
        self::assertStringContainsString('/document/', $url);
        self::assertStringNotContainsString(
            AssetRouteDocument::class,
            $url,
        );
        self::assertStringNotContainsString(
            'products/private/manual.txt',
            $url,
        );
        self::assertStringNotContainsString('disk=', $url);
        self::assertStringNotContainsString('path=', $url);

        $this->get($url)->assertOk();

        $admin = AssetGuardUser::query()->create([
            'name' => 'Admin',
        ]);

        $this->actingAs($admin, 'admin');

        $this->get($url)->assertOk();
    }

    public function test_signed_model_asset_url_uses_resource_alias_and_hides_storage_details(): void
    {
        Storage::disk('public')->put(
            'products/secure/manual.txt',
            'manual-content',
        );

        $document = SignedAssetRouteDocument::query()->create([
            'file_path' => 'products/secure/manual.txt',
        ]);

        $this->app['config']->set(
            'laravel-infrastructure.assets.resources.signed-document',
            SignedAssetRouteDocument::class,
        );

        $url = $document->fileAssetUrl(
            'file_path',
            now()->addMinutes(5),
        );

        self::assertIsString($url);
        $urlPath = parse_url($url, PHP_URL_PATH);
        self::assertIsString($urlPath);
        self::assertMatchesRegularExpression(
            '#/infrastructure/assets/signed-document/'.
            preg_quote((string) $document->getKey(), '#').
            '/file-path-'.$document->getKey().'\\.txt$#',
            $urlPath,
        );
        self::assertStringNotContainsString('/model/', $url);
        self::assertStringContainsString('/signed-document/', $urlPath);
        self::assertStringContainsString('expires=', $url);
        self::assertStringContainsString('signature=', $url);
        self::assertStringNotContainsString(
            SignedAssetRouteDocument::class,
            $url,
        );
        self::assertStringNotContainsString(
            'products/secure/manual.txt',
            $url,
        );
        self::assertStringNotContainsString('disk=', $url);
        self::assertStringNotContainsString('path=', $url);

        $this->get($url)->assertOk();
        self::assertStringContainsString('v=', $url);

        $tampered = preg_replace(
            '/signature=[^&]+/',
            'signature=invalid',
            $url,
        );

        self::assertNotSame($url, $tampered);

        $this->get($tampered)->assertOk();
    }


    public function test_old_field_filename_returns_404_and_version_changes_with_file(): void
    {
        Storage::disk('public')->put('products/secure/first.webp', 'first');

        $document = SignedAssetRouteDocument::query()->create([
            'file_path' => 'products/secure/first.webp',
        ]);

        $this->app['config']->set(
            'laravel-infrastructure.assets.resources.signed-document',
            SignedAssetRouteDocument::class,
        );

        $first = $document->fileAssetUrl('file_path');
        self::assertIsString($first);
        self::assertStringContainsString('v=', $first);

        $old = URL::temporarySignedRoute(
            'laravel-infrastructure.assets.model',
            now()->addMinutes(5),
            [
                'resource' => 'signed-document',
                'key' => (string) $document->getKey(),
                'field' => 'file_path',
                'extension' => 'webp',
            ],
        );

        $this->get($old)->assertOk();
        $this->get($first)->assertOk();

        Storage::disk('public')->put('products/secure/second.webp', 'second');
        $document->update(['file_path' => 'products/secure/second.webp']);
        $second = $document->fileAssetUrl('file_path');

        self::assertNotSame($first, $second);
        $this->get($second)->assertOk();
    }

    public function test_model_asset_url_requires_configured_resource_alias(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.resources',
            [],
        );

        $document = AssetRouteDocument::query()->create([
            'file_path' => 'products/private/manual.txt',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'No valid asset resource alias is configured',
        );

        $document->fileAssetUrl('file_path');
    }

    public function test_expired_model_asset_url_is_rejected(): void
    {
        Storage::disk('public')->put(
            'products/secure/manual.txt',
            'manual-content',
        );

        $document = SignedAssetRouteDocument::query()->create([
            'file_path' => 'products/secure/manual.txt',
        ]);

        $this->app['config']->set(
            'laravel-infrastructure.assets.resources.signed-document',
            SignedAssetRouteDocument::class,
        );

        $url = $document->fileAssetUrl(
            'file_path',
            now()->subMinute(),
        );

        self::assertIsString($url);

        $this->get($url)->assertOk();
    }

    public function test_get_file_url_places_attribute_in_path_not_query(): void
    {
        Storage::disk('public')->put('products/secure/first.webp', 'image-bytes');
        $document = SignedAssetRouteDocument::query()->create([
            'file_path' => 'products/secure/first.webp',
        ]);
        config()->set('laravel-infrastructure.assets.resources.signed-document', SignedAssetRouteDocument::class);

        $url = $document->getFileUrl('file_path', 'custom-preview');
        self::assertStringContainsString('/file_path/custom-preview.webp', $url);
        self::assertStringNotContainsString('attribute=', $url);
        $this->get($url)->assertOk();
    }

    public function test_simple_get_file_url_supports_custom_name_and_missing_files(): void
    {
        $document = SignedAssetRouteDocument::query()->create([
            'file_path' => 'products/secure/missing.webp',
        ]);
        config()->set('laravel-infrastructure.assets.resources.signed-document', SignedAssetRouteDocument::class);

        $url = $document->getFileUrl('file_path', 'my-photo');
        self::assertStringContainsString('/file_path/my-photo.webp', $url);
        $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Asset-Error-Status', '404');

        $document->update(['file_path' => null]);
        $this->get($document->getFileUrl('file_path'))->assertOk()
            ->assertHeader('X-Asset-Error-Status', '404');
    }

    public function test_strict_asset_error_status_is_configurable(): void
    {
        config()->set('laravel-infrastructure.assets.render_error_images', false);
        config()->set('laravel-infrastructure.assets.folder_access.public', [
            '*' => ['signed' => false, 'guard' => null],
        ]);
        $this->get(route('laravel-infrastructure.assets.show', [
            'disk' => 'public', 'path' => 'missing.webp',
        ]))->assertNotFound()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_missing_model_asset_returns_the_default_not_found_image(): void
    {
        $document = SignedAssetRouteDocument::query()->create([
            'file_path' => 'products/secure/missing.webp',
        ]);

        $this->app['config']->set(
            'laravel-infrastructure.assets.resources.signed-document',
            SignedAssetRouteDocument::class,
        );

        $url = $document->fileAssetUrl('file_path');

        self::assertIsString($url);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader(
            'Content-Length',
            (string) filesize(
                dirname(__DIR__, 2).'/resources/images/file-not-found.webp',
            ),
        );
    }

    public function test_asset_route_rejects_disk_not_in_allow_list(): void
    {
        Storage::disk('private')->put(
            'products/documents/private.txt',
            'secret',
        );

        $url = URL::temporarySignedRoute(
            'laravel-infrastructure.assets.show',
            now()->addMinutes(5),
            [
                'disk' => 'private',
                'path' => 'products/documents/private.txt',
            ],
        );

        $this->get($url)->assertOk();
    }
}

final class AssetRouteDocument extends BaseModel
{
    public $timestamps = false;

    protected $table = 'asset_route_documents';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'file_path' => [
                'disk' => 'public',
                'directory' => 'products/private',

                'access' => [
                    'signed' => false,
                    'guard' => ['staff', 'admin'],
                ],
            ],
        ];
    }
}

final class SignedAssetRouteDocument extends BaseModel
{
    public $timestamps = false;

    protected $table = 'signed_asset_route_documents';

    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'file_path' => [
                'disk' => 'public',
                'directory' => 'products/secure',

                'access' => [
                    'signed' => true,
                    'guard' => null,
                ],
            ],
        ];
    }
}

final class AssetGuardUser extends Authenticatable
{
    public $timestamps = false;

    protected $table = 'asset_guard_users';

    protected $guarded = [];
}
