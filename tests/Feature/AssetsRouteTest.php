<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class AssetsRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
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

    public function test_asset_route_enforces_configured_disk_gate_ability(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.assets.disk_abilities.public',
            'view-product-assets',
        );

        Gate::define(
            'view-product-assets',
            static fn (): bool => false,
        );

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
            ->assertForbidden();
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
