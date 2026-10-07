<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
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
            'model' => AssetAdminUser::class,
        ]);
        $app['config']->set('auth.guards.admin', [
            'driver' => 'session',
            'provider' => 'asset_users',
        ]);

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
            'asset_admin_users',
            function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('role')->nullable();
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

        $this->get($url)
            ->assertOk();
    }

    public function test_asset_route_rejects_unsigned_request_by_default(): void
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

    public function test_folder_rule_can_make_one_folder_unsigned_without_affecting_siblings(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.folder_access.public',
            [
                '*' => [
                    'signed' => true,
                ],
                'products/images' => [
                    'signed' => false,
                ],
            ],
        );

        Storage::disk('public')->put(
            'products/images/photo.txt',
            'photo',
        );
        Storage::disk('public')->put(
            'products/documents/manual.txt',
            'manual',
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

    public function test_most_specific_folder_rule_enforces_its_own_gate_ability(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.folder_access.public',
            [
                '*' => [
                    'signed' => false,
                ],
                'products' => [
                    'ability' => 'view-products',
                ],
                'products/admin' => [
                    'ability' => 'view-admin-products',
                ],
            ],
        );

        Gate::define(
            'view-products',
            static fn (): bool => true,
        );
        Gate::define(
            'view-admin-products',
            static fn (): bool => false,
        );

        Storage::disk('public')->put(
            'products/catalog.txt',
            'catalog',
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
                    'path' => 'products/catalog.txt',
                ],
            ),
        )->assertOk();

        $this->get(
            route(
                'laravel-infrastructure.assets.show',
                [
                    'disk' => 'public',
                    'path' => 'products/admin/report.txt',
                ],
            ),
        )->assertForbidden();
    }

    public function test_model_file_access_can_define_guard_role_and_signed_behavior(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.folder_access.public',
            [
                '*' => [
                    'signed' => true,
                ],
            ],
        );

        Storage::disk('public')->put(
            'products/public/manual.txt',
            'manual-content',
        );

        $document = AssetRouteDocument::query()->create([
            'file_path' => 'products/public/manual.txt',
        ]);

        $url = $document->fileAssetUrl('file_path');

        self::assertIsString($url);

        // Model field requires the admin guard + admin role.
        $this->get($url)
            ->assertUnauthorized();

        $admin = AssetAdminUser::query()->create([
            'name' => 'Admin',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'admin');

        // Model field also overrides the folder fallback signed=true.
        $this->get($url)
            ->assertOk();
    }

    public function test_model_file_url_cannot_be_retargeted_to_another_path(): void
    {
        $admin = AssetAdminUser::query()->create([
            'name' => 'Admin',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'admin');

        Storage::disk('public')->put(
            'products/public/manual.txt',
            'manual-content',
        );
        Storage::disk('public')->put(
            'products/public/other.txt',
            'other-content',
        );

        $document = AssetRouteDocument::query()->create([
            'file_path' => 'products/public/manual.txt',
        ]);

        $this->get(
            route(
                'laravel-infrastructure.assets.show',
                [
                    'disk' => 'public',
                    'path' => 'products/public/other.txt',
                    'model' => AssetRouteDocument::class,
                    'key' => (string) $document->getKey(),
                    'field' => 'file_path',
                ],
            ),
        )->assertNotFound();
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

        $this->get($url)
            ->assertNotFound();
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
                'directory' => 'products/public',

                'access' => [
                    'signed' => false,
                    'guard' => 'admin',
                    'roles' => ['admin'],
                ],
            ],
        ];
    }
}

final class AssetAdminUser extends Authenticatable
{
    public $timestamps = false;

    protected $table = 'asset_admin_users';

    protected $guarded = [];
}
