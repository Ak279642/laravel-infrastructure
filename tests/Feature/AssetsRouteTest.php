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
        )->assertForbidden();
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

        $this->get($protectedUrl)->assertUnauthorized();

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
        self::assertStringContainsString(
            '/infrastructure/assets/model/document/'.
            $document->getKey().
            '/file_path',
            $url,
        );
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

        $this->get($url)->assertUnauthorized();

        $admin = AssetGuardUser::query()->create([
            'name' => 'Admin',
        ]);

        $this->actingAs($admin, 'admin');

        $this->get($url)->assertOk();
    }

    public function test_signed_model_asset_url_uses_hash_and_hides_storage_details(): void
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
        self::assertStringContainsString(
            '/infrastructure/assets/model/signed-document/'.
            $document->getKey().
            '/file_path',
            $url,
        );
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

        $tampered = str_replace(
            '/file_path?',
            '/other_path?',
            $url,
        );

        self::assertNotSame($url, $tampered);

        $this->get($tampered)->assertForbidden();
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

        $this->get($url)->assertNotFound();
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
                    'guard' => 'admin',
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
