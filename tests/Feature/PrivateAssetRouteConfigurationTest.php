<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class PrivateAssetRouteConfigurationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.assets.private_route_path', 'secure/files');
        $app['config']->set('laravel-infrastructure.assets.private_route_name', 'secure.files');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Schema::create('configured_private_assets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('path')->nullable();
        });
    }

    public function test_custom_private_route_uses_web_sessions_and_keeps_signature_and_ownership_checks(): void
    {
        $route = Route::getRoutes()->getByName('secure.files');
        self::assertNotNull($route);
        self::assertSame('secure/files/{token}', $route->uri());
        self::assertContains('web', $route->gatherMiddleware());
        self::assertNull(Route::getRoutes()->getByName('laravel-infrastructure.assets.private'));

        Storage::disk('private')->put('contracts/test.pdf', 'secret document');
        $model = ConfiguredPrivateAsset::query()->create([
            'owner_id' => 42, 'path' => 'contracts/test.pdf',
        ]);

        $url = $model->fileAssetUrl('path');
        self::assertNotNull($url);
        self::assertStringContainsString('/secure/files/', $url);

        $this->get($url)->assertHeader('X-Asset-Error-Status', '403');

        $other = new ConfiguredAssetUser;
        $other->id = 99;
        $this->actingAs($other, 'web');
        $this->get($url)->assertHeader('X-Asset-Error-Status', '403');

        $owner = new ConfiguredAssetUser;
        $owner->id = 42;
        $this->actingAs($owner, 'web');
        $this->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $tampered = preg_replace('/signature=[^&]+/', 'signature=invalid', $url);
        $this->get($tampered)->assertHeader('X-Asset-Error-Status', '403');
    }
}

final class ConfiguredPrivateAsset extends BaseModel
{
    public $timestamps = false;
    protected $table = 'configured_private_assets';
    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'path' => [
                'disk' => 'private',
                'directory' => 'contracts',
                'access' => ['guard' => 'web', 'signed' => true],
            ],
        ];
    }

    public function authorizesAssetField(string $field): bool
    {
        return $field === 'path' && (int) auth('web')->id() === (int) $this->owner_id;
    }
}

final class ConfiguredAssetUser extends Authenticatable {}
