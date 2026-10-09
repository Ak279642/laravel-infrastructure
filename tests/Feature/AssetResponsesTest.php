<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class AssetResponsesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-infrastructure.assets.allowed_disks', ['public']);
        $app['config']->set('laravel-infrastructure.assets.legacy_uploads', [
            'enabled' => true,
            'prefix' => 'uploads',
            'disk' => 'public',
        ]);
        $app['config']->set('laravel-infrastructure.assets.folder_access.public', [
            '*' => ['signed' => false, 'guard' => null],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_asset_cache_policy_requires_no_package_cache_settings(): void
    {
        self::assertArrayNotHasKey('cache', config('laravel-infrastructure.assets'));
    }

    public function test_missing_file_returns_404_image_not_html(): void
    {
        $this->get(route('laravel-infrastructure.assets.show', [
            'disk' => 'public', 'path' => 'missing.webp',
        ]))->assertNotFound()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0');
    }

    public function test_access_denied_returns_403_image_not_html(): void
    {
        config()->set('laravel-infrastructure.assets.folder_access.public', [
            '*' => ['signed' => true, 'guard' => null],
        ]);

        $this->get(route('laravel-infrastructure.assets.show', [
            'disk' => 'public', 'path' => 'private.webp',
        ]))->assertForbidden()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0');
    }

    public function test_missing_route_renders_404_image(): void
    {
        $this->get('/infrastructure/assets/invalid')->assertNotFound()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_custom_global_404_image_override_is_used(): void
    {
        config()->set('laravel-infrastructure.assets.error_images.404',
            dirname(__DIR__, 2).'/resources/images/file-access-denied.webp');

        $this->get(route('laravel-infrastructure.assets.show', [
            'disk' => 'public', 'path' => 'missing.webp',
        ]))->assertNotFound()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Content-Length', (string) filesize(
                dirname(__DIR__, 2).'/resources/images/file-access-denied.webp'));
    }

    public function test_public_images_support_etag_and_conditional_get(): void
    {
        Storage::disk('public')->put('assets/logo.webp', file_get_contents(
            dirname(__DIR__, 2).'/resources/images/file-not-found.webp'));

        $url = route('laravel-infrastructure.assets.show', [
            'disk' => 'public', 'path' => 'assets/logo.webp',
        ]);

        $response = $this->get($url);
        $response->assertOk()->assertHeader('Cache-Control', 'public, max-age=86400');
        $etag = $response->headers->get('ETag');
        self::assertIsString($etag);
        self::assertNotSame('', $etag);

        $this->withHeaders(['If-None-Match' => $etag])->get($url)->assertStatus(304);
    }

    public function test_signed_images_remain_uncached_by_default(): void
    {
        Storage::disk('public')->put('assets/private.webp', file_get_contents(
            dirname(__DIR__, 2).'/resources/images/file-not-found.webp'));
        config()->set('laravel-infrastructure.assets.folder_access.public', [
            '*' => ['signed' => true, 'guard' => null],
        ]);

        $url = URL::temporarySignedRoute('laravel-infrastructure.assets.show',
            now()->addMinutes(5), ['disk' => 'public', 'path' => 'assets/private.webp']);

        $this->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0');
    }

    public function test_uploads_alias_uses_same_package_access_checks(): void
    {
        Storage::disk('public')->put('assets/logo.webp', file_get_contents(
            dirname(__DIR__, 2).'/resources/images/file-not-found.webp'));

        $this->get(route('uploads', ['file' => 'assets/logo.webp']))->assertOk();

        config()->set('laravel-infrastructure.assets.folder_access.public', [
            '*' => ['signed' => true, 'guard' => null],
        ]);
        $this->get(route('uploads', ['file' => 'assets/logo.webp']))
            ->assertForbidden()->assertHeader('Content-Type', 'image/webp');
    }
}
