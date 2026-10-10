<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

final class AssetResponsesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-infrastructure.assets.disk_aliases', ['media' => 'public']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_missing_public_file_returns_default_404_image(): void
    {
        $this->get('/media/products/missing.webp')->assertOk()
            ->assertHeader('X-Asset-Error-Status', '404')
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_public_image_is_cached_and_legacy_route_is_absent(): void
    {
        Storage::disk('public')->put('products/logo.webp', file_get_contents(
            dirname(__DIR__, 2).'/resources/images/file-not-found.webp',
        ));
        $this->get('/media/products/logo.webp')->assertOk()
            ->assertHeader('Cache-Control', 'public, max-age=86400');
        self::assertNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('laravel-infrastructure.assets.show'));
        self::assertNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('uploads'));
    }

    public function test_public_route_rejects_hidden_and_executable_files(): void
    {
        Storage::disk('public')->put('products/script.php', '<?php echo 1;');
        $this->get('/media/products/script.php')->assertHeader('X-Asset-Error-Status', '404');
        $this->get('/media/.env')->assertHeader('X-Asset-Error-Status', '404');
    }
}
